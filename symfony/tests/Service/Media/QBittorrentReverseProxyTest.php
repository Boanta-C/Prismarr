<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Issue #10 — qBittorrent behind a reverse proxy (qui, traefik forward
 * auth, …) must work with EMPTY user/password in DB. The proxy URL contains
 * a secret token and the proxy itself injects the credentials, so Prismarr
 * is supposed to leave the auth flow alone:
 *  - ensureConfig() must NOT throw when user/password are missing
 *  - login() must short-circuit to a sentinel SID without hitting the
 *    network (no /auth/login round-trip with an empty body, which qBit
 *    would answer "Fails." even though the proxy is fine)
 */
#[AllowMockObjectsWithoutExpectations]
class QBittorrentReverseProxyTest extends TestCase
{
    private function makeClient(ConfigService $config): QBittorrentClient
    {
        return new QBittorrentClient(
            $config,
            $this->createMock(LoggerInterface::class),
            new ServiceHealthCache(new ArrayAdapter()),
        );
    }

    public function testEnsureConfigDoesNotThrowWhenUserAndPasswordAreEmpty(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('require')->willReturn('http://qui.lan:7476/proxy/abc123');
        $config->method('get')->willReturn(null); // user / password absent

        $client = $this->makeClient($config);

        $ref = new \ReflectionClass($client);
        $ensure = $ref->getMethod('ensureConfig');
        $ensure->setAccessible(true);

        // Critical: this is what "qBit behind reverse-proxy" boils down to.
        // Pre-fix, require() threw ServiceNotConfiguredException and crashed
        // every page that touched QBittorrentClient.
        $ensure->invoke($client);

        $baseUrl = $ref->getProperty('baseUrl');
        $baseUrl->setAccessible(true);
        $this->assertSame('http://qui.lan:7476/proxy/abc123', $baseUrl->getValue($client));

        $user = $ref->getProperty('user');
        $user->setAccessible(true);
        $this->assertSame('', $user->getValue($client));

        $password = $ref->getProperty('password');
        $password->setAccessible(true);
        $this->assertSame('', $password->getValue($client));
    }

    public function testLoginShortCircuitsToSentinelWhenCredentialsAreEmpty(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('require')->willReturn('http://qui.lan:7476/proxy/abc123');
        $config->method('get')->willReturn(null);

        $client = $this->makeClient($config);

        $ref   = new \ReflectionClass($client);
        $login = $ref->getMethod('login');
        $login->setAccessible(true);

        // If the short-circuit didn't trigger, login() would attempt a real
        // curl POST to /auth/login and either succeed (returning a real SID)
        // or fail (returning null + arming the circuit breaker). Either way,
        // we'd see a non-sentinel result here. The sentinel proves we
        // bypassed the network entirely.
        $sid = $login->invoke($client);

        $this->assertIsString($sid);
        $this->assertSame('__noauth__', $sid);
    }

    public function testLoginShortCircuitsWhenOnlyOneCredentialIsEmpty(): void
    {
        // Defensive case: user typed a username but forgot the password.
        // We treat this as "reverse proxy mode" too — see HealthService
        // probeFor() comment. The test is intentionally driven by config
        // values "admin" + "" so we know which branch was taken.
        $config = $this->createMock(ConfigService::class);
        $config->method('require')->willReturn('http://qbit.lan:8080');
        $config->method('get')->willReturnCallback(fn (string $k) => match ($k) {
            'qbittorrent_user'     => 'admin',
            'qbittorrent_password' => null,
            default                => null,
        });

        $client = $this->makeClient($config);

        $ref   = new \ReflectionClass($client);
        $login = $ref->getMethod('login');
        $login->setAccessible(true);
        $sid   = $login->invoke($client);

        $this->assertSame('__noauth__', $sid);
    }
}
