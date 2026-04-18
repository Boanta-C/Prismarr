<?php

namespace App\Service\Mac;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP partagé pour les endpoints "ops" de l'agent Python du Mac Mini.
 *
 * - metrics-agent.py (port 9099, public) : lectures pures
 *     → /mounts, /mount-log
 * - host-manager.py (port 9098, tokenisé) : actions à effet de bord
 *     → /db-integrity, /mounts/clean-crap (session future)
 *
 * Si l'agent est injoignable (coupure, agent arrêté), on log en debug et on
 * renvoie null — les workers appelants gèrent la dégradation.
 */
class MacAgentClient
{
    public function __construct(
        private readonly LoggerInterface     $logger,
        private readonly HttpClientInterface $http,
        private readonly string              $agentUrl,
        private readonly string              $managerUrl = '',
        private readonly string              $managerToken = '',
    ) {}

    /**
     * État des 5 mounts NFS (films, series, animes-films, animes-series, downloads).
     *
     * @return array{mounts: array, up_count: int, down_count: int, total: int, collected_at: int}|null
     */
    public function fetchMounts(): ?array
    {
        return $this->get('/mounts');
    }

    /**
     * Tail de /var/log/mount-nas.log + parsing du dernier run du LaunchDaemon.
     *
     * @return array{available: bool, last_run: ?string, last_exit: ?int, last_failed: ?int, recent_lines: array}|null
     */
    public function fetchMountLog(): ?array
    {
        return $this->get('/mount-log');
    }

    /**
     * Lance PRAGMA integrity_check sur les DB SQLite de containers ciblés.
     * Passe par host-manager (endpoint tokenisé, port 9098) qui copie chaque
     * DB via docker cp puis exécute sqlite3 dans un container Alpine sidecar.
     *
     * @param array<int, array{container: string, db_path: string, label: string}> $checks
     * @return array{checks: array<int, array{container: string, db_path: string, label: string, result: string, report: string, duration_ms: int, size_bytes: ?int}>}|null
     */
    public function runDbIntegrity(array $checks): ?array
    {
        return $this->postToManager('/db-integrity', ['checks' => $checks], 600);
    }

    /**
     * Supprime les fichiers parasites macOS (._* et .DS_Store) sur les 5 mounts NFS.
     * Skip les mounts non actifs. Retourne le nombre de fichiers supprimés par mount.
     *
     * @return array{cleaned: array<string, int>, skipped: array<int, string>, total: int, duration_ms: int, errors: array}|null
     */
    public function cleanMacCrap(): ?array
    {
        return $this->postToManager('/mounts/clean-crap', [], 600);
    }

    // -----------------------------------------------------------------------

    private function get(string $path): ?array
    {
        if ($this->agentUrl === '') {
            return null;
        }

        try {
            $response = $this->http->request('GET', rtrim($this->agentUrl, '/') . $path, [
                'timeout' => 5,
            ]);
            return $response->toArray();
        } catch (\Throwable $e) {
            // Debug : agent offline courant après reboot Mac — pas d'alerte spam
            $this->logger->debug("MacAgentClient GET {$path} error: " . $e->getMessage());
            return null;
        }
    }

    private function postToManager(string $path, array $body, int $timeout = 30): ?array
    {
        if ($this->managerUrl === '' || $this->managerToken === '') {
            return null;
        }

        try {
            $response = $this->http->request('POST', rtrim($this->managerUrl, '/') . $path, [
                'timeout' => $timeout,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'X-Argos-Token' => $this->managerToken,
                ],
                'json' => $body,
            ]);
            return $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning("MacAgentClient POST {$path} error: " . $e->getMessage());
            return null;
        }
    }
}
