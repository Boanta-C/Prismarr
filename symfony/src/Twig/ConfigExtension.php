<?php

namespace App\Twig;

use App\Service\ConfigService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ConfigExtension extends AbstractExtension
{
    /**
     * Clés minimales qui signent la présence d'un service configuré.
     * @var array<string, string>
     */
    private const SERVICE_KEYS = [
        'tmdb'        => 'tmdb_api_key',
        'radarr'      => 'radarr_api_key',
        'sonarr'      => 'sonarr_api_key',
        'prowlarr'    => 'prowlarr_api_key',
        'jellyseerr'  => 'jellyseerr_api_key',
        'qbittorrent' => 'qbittorrent_url',
        'gluetun'     => 'gluetun_url',
    ];

    public function __construct(
        private readonly ConfigService $config,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('service_configured', [$this, 'isServiceConfigured']),
        ];
    }

    public function isServiceConfigured(string $service): bool
    {
        $key = self::SERVICE_KEYS[$service] ?? null;
        return $key !== null && $this->config->has($key);
    }
}
