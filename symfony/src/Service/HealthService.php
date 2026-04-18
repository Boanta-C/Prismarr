<?php

namespace App\Service;

use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;

/**
 * Teste la disponibilité des services tiers. Cache mémoire par-process :
 * une seule vérification HTTP par worker FrankenPHP (qui dure quelques minutes).
 */
class HealthService
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(
        private readonly RadarrClient      $radarr,
        private readonly SonarrClient      $sonarr,
        private readonly ProwlarrClient    $prowlarr,
        private readonly JellyseerrClient  $jellyseerr,
        private readonly QBittorrentClient $qbittorrent,
        private readonly TmdbClient        $tmdb,
    ) {}

    public function isHealthy(string $service): bool
    {
        if (isset($this->cache[$service])) {
            return $this->cache[$service];
        }

        $ok = match ($service) {
            'radarr'      => $this->radarr->ping(),
            'sonarr'      => $this->sonarr->ping(),
            'prowlarr'    => $this->prowlarr->ping(),
            'jellyseerr'  => $this->jellyseerr->ping(),
            'qbittorrent' => $this->qbittorrent->ping(),
            'tmdb'        => $this->tmdb->ping(),
            default       => true,
        };

        return $this->cache[$service] = $ok;
    }

    /** Invalide le cache — utile après une reconfiguration via l'admin. */
    public function invalidate(?string $service = null): void
    {
        if ($service === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$service]);
        }
    }
}
