<?php

namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HealthServiceTest extends TestCase
{
    private function makeService(
        ?RadarrClient $radarr = null,
        ?SonarrClient $sonarr = null,
        ?ProwlarrClient $prowlarr = null,
        ?JellyseerrClient $jellyseerr = null,
        ?QBittorrentClient $qbittorrent = null,
        ?TmdbClient $tmdb = null,
        ?ConfigService $config = null,
    ): HealthService {
        return new HealthService(
            $radarr      ?? $this->createMock(RadarrClient::class),
            $sonarr      ?? $this->createMock(SonarrClient::class),
            $prowlarr    ?? $this->createMock(ProwlarrClient::class),
            $jellyseerr  ?? $this->createMock(JellyseerrClient::class),
            $qbittorrent ?? $this->createMock(QBittorrentClient::class),
            $tmdb        ?? $this->createMock(TmdbClient::class),
            $config,
        );
    }

    public function testIsHealthyCallsTheRightClient(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->once())->method('ping')->willReturn(true);

        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->expects($this->never())->method('ping');

        $svc = $this->makeService($radarr, $sonarr);
        $this->assertTrue($svc->isHealthy('radarr'));
    }

    public function testUnknownServiceReturnsTrue(): void
    {
        // No client should be called for an unknown service.
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->never())->method('ping');

        $svc = $this->makeService($radarr);
        $this->assertTrue($svc->isHealthy('nonexistent'));
    }

    public function testCacheHitsAvoidSecondPing(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        // Exactly 1 ping — the second isHealthy() must hit the cache.
        $radarr->expects($this->once())->method('ping')->willReturn(true);

        $svc = $this->makeService($radarr);
        $svc->isHealthy('radarr');
        $svc->isHealthy('radarr');
    }

    public function testCachedFailureIsReturnedAsIs(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->once())->method('ping')->willReturn(false);

        $svc = $this->makeService($radarr);
        $this->assertFalse($svc->isHealthy('radarr'));
        // Still false on second call — and no extra ping.
        $this->assertFalse($svc->isHealthy('radarr'));
    }

    public function testInvalidateForOneServiceForcesReping(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->exactly(2))->method('ping')->willReturn(true);

        $svc = $this->makeService($radarr);
        $svc->isHealthy('radarr');
        $svc->invalidate('radarr');
        $svc->isHealthy('radarr');
    }

    public function testInvalidateAllForcesRepingForAllServices(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->exactly(2))->method('ping')->willReturn(true);
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->expects($this->exactly(2))->method('ping')->willReturn(true);

        $svc = $this->makeService($radarr, $sonarr);
        $svc->isHealthy('radarr');
        $svc->isHealthy('sonarr');
        $svc->invalidate();
        $svc->isHealthy('radarr');
        $svc->isHealthy('sonarr');
    }

    /**
     * @return array<string, array{0: array{http: ?int, body: ?string, err: string}, 1: string, 2: string, 3: bool}>
     */
    public static function diagnoseCases(): array
    {
        return [
            // [response tuple, service, expected category, expected ok]
            'curl error → network'         => [['http' => null, 'body' => null, 'err' => 'Could not resolve host'], 'radarr', 'network', false],
            'http 200 → ok'                => [['http' => 200,  'body' => '{}',  'err' => ''], 'radarr', 'ok',           true],
            'http 204 → ok'                => [['http' => 204,  'body' => '',    'err' => ''], 'sonarr', 'ok',           true],
            'http 401 → auth'              => [['http' => 401,  'body' => '',    'err' => ''], 'radarr', 'auth',         false],
            'http 403 → forbidden'         => [['http' => 403,  'body' => 'banned', 'err' => ''], 'qbittorrent', 'forbidden', false],
            'http 404 → not_found'         => [['http' => 404,  'body' => '',    'err' => ''], 'jellyseerr', 'not_found', false],
            'http 500 → server_error'      => [['http' => 500,  'body' => '',    'err' => ''], 'tmdb',  'server_error', false],
            'http 502 → server_error'      => [['http' => 502,  'body' => '',    'err' => ''], 'sonarr','server_error', false],
            'qbit 200 + Fails. → auth'     => [['http' => 200,  'body' => 'Fails.', 'err' => ''], 'qbittorrent', 'auth', false],
            'qbit 200 + Ok. → ok'          => [['http' => 200,  'body' => 'Ok.', 'err' => ''], 'qbittorrent', 'ok',     true],
            'http 418 → unknown'           => [['http' => 418,  'body' => '',    'err' => ''], 'radarr', 'unknown',      false],
        ];
    }

    /**
     * @param array{http: ?int, body: ?string, err: string} $resp
     */
    #[DataProvider('diagnoseCases')]
    public function testDiagnoseFromResponseMapsHttpCodesToCategories(array $resp, string $service, string $expectedCategory, bool $expectedOk): void
    {
        $svc = $this->makeService();
        $diag = $svc->diagnoseFromResponse($resp, $service);

        $this->assertSame($expectedCategory, $diag['category']);
        $this->assertSame($expectedOk, $diag['ok']);
        // The http code is preserved (or null on network failure).
        $this->assertSame($resp['err'] !== '' ? null : $resp['http'], $diag['http']);
    }

    public function testDiagnoseReturnsUnconfiguredWhenConfigMissing(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);

        $svc = $this->makeService(config: $config);
        $diag = $svc->diagnose('radarr');

        $this->assertFalse($diag['ok']);
        $this->assertSame('unconfigured', $diag['category']);
    }

    public function testDiagnoseReturnsUnknownWhenServiceIdIsBogus(): void
    {
        $config = $this->createMock(ConfigService::class);
        $svc = $this->makeService(config: $config);
        $diag = $svc->diagnose('does-not-exist');

        $this->assertFalse($diag['ok']);
        $this->assertSame('unconfigured', $diag['category']);
    }

    public function testEachServiceMappedToItsClient(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $sonarr = $this->createMock(SonarrClient::class);
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $jellyseerr = $this->createMock(JellyseerrClient::class);
        $qbit = $this->createMock(QBittorrentClient::class);
        $tmdb = $this->createMock(TmdbClient::class);

        $radarr->expects($this->once())->method('ping')->willReturn(true);
        $sonarr->expects($this->once())->method('ping')->willReturn(true);
        $prowlarr->expects($this->once())->method('ping')->willReturn(true);
        $jellyseerr->expects($this->once())->method('ping')->willReturn(true);
        $qbit->expects($this->once())->method('ping')->willReturn(true);
        $tmdb->expects($this->once())->method('ping')->willReturn(true);

        $svc = $this->makeService($radarr, $sonarr, $prowlarr, $jellyseerr, $qbit, $tmdb);
        $svc->isHealthy('radarr');
        $svc->isHealthy('sonarr');
        $svc->isHealthy('prowlarr');
        $svc->isHealthy('jellyseerr');
        $svc->isHealthy('qbittorrent');
        $svc->isHealthy('tmdb');
    }
}
