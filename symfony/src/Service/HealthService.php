<?php

namespace App\Service;

use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;

/**
 * Tests third-party service availability.
 *
 * Two flavors:
 *  - isHealthy() returns a cached bool — used by topbar/dashboard widgets
 *    that poll often.
 *  - diagnose() probes the URL directly to categorize WHY the service is
 *    down (network / auth / forbidden / not_found / server_error / ...) so
 *    the admin "Test connection" button can return an actionable hint
 *    without leaking internal stack traces.
 */
class HealthService
{
    private const CACHE_TTL = 10;

    /** @var array<string, array{ok: bool, at: int}> */
    private array $cache = [];

    public function __construct(
        private readonly RadarrClient      $radarr,
        private readonly SonarrClient      $sonarr,
        private readonly ProwlarrClient    $prowlarr,
        private readonly JellyseerrClient  $jellyseerr,
        private readonly QBittorrentClient $qbittorrent,
        private readonly TmdbClient        $tmdb,
        private readonly ?ConfigService    $config = null,
    ) {}

    public function isHealthy(string $service): bool
    {
        $now = time();
        if (isset($this->cache[$service]) && ($now - $this->cache[$service]['at']) < self::CACHE_TTL) {
            return $this->cache[$service]['ok'];
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

        $this->cache[$service] = ['ok' => $ok, 'at' => $now];
        return $ok;
    }

    /** Invalidate the cache — useful after a reconfiguration via admin. */
    public function invalidate(?string $service = null): void
    {
        if ($service === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$service]);
        }
    }

    /**
     * Probe a service directly and return a categorized diagnosis the admin
     * UI can show. Returns ['ok' => bool, 'category' => string, 'http' => ?int].
     * Categories: ok / unconfigured / network / auth / forbidden / not_found
     * / server_error / unknown.
     */
    public function diagnose(string $service): array
    {
        if ($this->config === null) {
            return ['ok' => false, 'category' => 'unknown', 'http' => null];
        }

        $probe = $this->probeFor($service);
        if ($probe === null) {
            return ['ok' => false, 'category' => 'unconfigured', 'http' => null];
        }

        $resp = $this->httpProbe(
            $probe['url'],
            $probe['headers'] ?? [],
            $probe['method'] ?? 'GET',
            $probe['body']    ?? null,
        );

        return $this->diagnoseFromResponse($resp, $service);
    }

    /**
     * Pure mapping from a (http, curlError, body) tuple to a diagnosis.
     * Public for testability — the curl-side is harder to mock.
     *
     * @param array{http: ?int, body: ?string, err: string} $resp
     * @return array{ok: bool, category: string, http: ?int}
     */
    public function diagnoseFromResponse(array $resp, string $service): array
    {
        $http = $resp['http'] ?? null;
        $err  = $resp['err']  ?? '';
        $body = $resp['body'] ?? null;

        if ($err !== '') {
            return ['ok' => false, 'category' => 'network', 'http' => null];
        }
        // qBittorrent: a wrong username/password returns HTTP 200 with the
        // literal body "Fails." — without this special case we'd mistake an
        // auth failure for a healthy response.
        if ($service === 'qbittorrent' && $http === 200 && is_string($body) && trim($body) === 'Fails.') {
            return ['ok' => false, 'category' => 'auth', 'http' => $http];
        }
        if ($http !== null && $http >= 200 && $http < 300) {
            return ['ok' => true, 'category' => 'ok', 'http' => $http];
        }
        if ($http === 401) return ['ok' => false, 'category' => 'auth',         'http' => $http];
        if ($http === 403) return ['ok' => false, 'category' => 'forbidden',    'http' => $http];
        if ($http === 404) return ['ok' => false, 'category' => 'not_found',    'http' => $http];
        if ($http !== null && $http >= 500) {
            return ['ok' => false, 'category' => 'server_error', 'http' => $http];
        }
        return ['ok' => false, 'category' => 'unknown', 'http' => $http];
    }

    /**
     * Build the probe parameters (URL, headers, method, body) for a given
     * service from the user's configuration. Returns null when the service
     * has no URL/credentials configured at all.
     *
     * @return ?array{url: string, headers?: array<int,string>, method?: string, body?: string}
     */
    private function probeFor(string $service): ?array
    {
        $cfg = $this->config;
        if ($cfg === null) return null;

        switch ($service) {
            case 'radarr':
            case 'sonarr':
            case 'prowlarr': {
                $url = (string) ($cfg->get($service . '_url') ?? '');
                $key = (string) ($cfg->get($service . '_api_key') ?? '');
                if ($url === '' || $key === '') return null;
                $version = $service === 'prowlarr' ? 'v1' : 'v3';
                return [
                    'url'     => rtrim($url, '/') . '/api/' . $version . '/system/status',
                    'headers' => ['X-Api-Key: ' . $key, 'Accept: application/json'],
                ];
            }
            case 'jellyseerr': {
                $url = (string) ($cfg->get('jellyseerr_url') ?? '');
                $key = (string) ($cfg->get('jellyseerr_api_key') ?? '');
                if ($url === '' || $key === '') return null;
                return [
                    'url'     => rtrim($url, '/') . '/api/v1/settings/about',
                    'headers' => ['X-Api-Key: ' . $key, 'Accept: application/json'],
                ];
            }
            case 'tmdb': {
                $key = (string) ($cfg->get('tmdb_api_key') ?? '');
                if ($key === '') return null;
                return [
                    'url'     => 'https://api.themoviedb.org/3/configuration?api_key=' . urlencode($key),
                    'headers' => ['Accept: application/json'],
                ];
            }
            case 'qbittorrent': {
                $url  = (string) ($cfg->get('qbittorrent_url') ?? '');
                $user = (string) ($cfg->get('qbittorrent_user') ?? '');
                $pass = (string) ($cfg->get('qbittorrent_password') ?? '');
                if ($url === '' || $user === '' || $pass === '') return null;
                return [
                    'url'     => rtrim($url, '/') . '/api/v2/auth/login',
                    'headers' => [
                        'Content-Type: application/x-www-form-urlencoded',
                        'Referer: ' . rtrim($url, '/'),
                    ],
                    'method'  => 'POST',
                    'body'    => http_build_query(['username' => $user, 'password' => $pass]),
                ];
            }
            default:
                return null;
        }
    }

    /**
     * Issue the curl request and return a normalized response array. Kept
     * private so we can swap the implementation later (e.g. Symfony's
     * HttpClient) without changing diagnose() callers.
     *
     * @param array<int, string> $headers
     * @return array{http: ?int, body: ?string, err: string}
     */
    private function httpProbe(string $url, array $headers, string $method, ?string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['http' => null, 'body' => null, 'err' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }
        $resBody = curl_exec($ch);
        $http    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err     = curl_error($ch);
        curl_close($ch);

        return [
            'http' => $http > 0 ? $http : null,
            'body' => is_string($resBody) ? $resBody : null,
            'err'  => $err,
        ];
    }
}
