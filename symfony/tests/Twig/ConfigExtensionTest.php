<?php

namespace App\Tests\Twig;

use App\Service\ConfigService;
use App\Twig\ConfigExtension;
use PHPUnit\Framework\TestCase;

class ConfigExtensionTest extends TestCase
{
    private function extension(array $configuredKeys): ConfigExtension
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturnCallback(
            fn(string $key) => in_array($key, $configuredKeys, true)
        );
        return new ConfigExtension($config);
    }

    public function testRegistersTheServiceConfiguredFunction(): void
    {
        $functions = ($this->extension([]))->getFunctions();
        $names = array_map(fn($fn) => $fn->getName(), $functions);
        $this->assertContains('service_configured', $names);
    }

    public function testRadarrConfiguredWhenApiKeyPresent(): void
    {
        $ext = $this->extension(['radarr_api_key']);
        $this->assertTrue($ext->isServiceConfigured('radarr'));
    }

    public function testRadarrNotConfiguredWhenApiKeyMissing(): void
    {
        $ext = $this->extension([]);
        $this->assertFalse($ext->isServiceConfigured('radarr'));
    }

    public function testQbittorrentConfiguredByUrlNotApiKey(): void
    {
        // qBittorrent uses URL as the presence indicator (no api_key concept).
        $ext = $this->extension(['qbittorrent_url']);
        $this->assertTrue($ext->isServiceConfigured('qbittorrent'));
    }

    public function testGluetunConfiguredByUrl(): void
    {
        $ext = $this->extension(['gluetun_url']);
        $this->assertTrue($ext->isServiceConfigured('gluetun'));
    }

    public function testUnknownServiceReturnsFalse(): void
    {
        $ext = $this->extension(['radarr_api_key', 'sonarr_api_key']);
        $this->assertFalse($ext->isServiceConfigured('unknown_service'));
    }

    public function testEachServiceMappedToExpectedKey(): void
    {
        $expectations = [
            'tmdb'        => 'tmdb_api_key',
            'radarr'      => 'radarr_api_key',
            'sonarr'      => 'sonarr_api_key',
            'prowlarr'    => 'prowlarr_api_key',
            'jellyseerr'  => 'jellyseerr_api_key',
            'qbittorrent' => 'qbittorrent_url',
            'gluetun'     => 'gluetun_url',
        ];

        foreach ($expectations as $service => $requiredKey) {
            $ext = $this->extension([$requiredKey]);
            $this->assertTrue(
                $ext->isServiceConfigured($service),
                "Expected $service to be configured when $requiredKey is present"
            );
        }
    }
}
