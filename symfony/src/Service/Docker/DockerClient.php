<?php

namespace App\Service\Docker;

use Psr\Log\LoggerInterface;

class DockerClient
{
    private const SOCKET_PATH = '/var/run/docker.sock';

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Retourne la liste de tous les conteneurs (actifs + arrêtés).
     *
     * @return array<int, array{
     *   id: string,
     *   name: string,
     *   image: string,
     *   status: string,
     *   state: string,
     *   created: int,
     *   ports: array
     * }>
     */
    public function getContainers(): array
    {
        $response = $this->request('GET', '/containers/json?all=true');

        if ($response === null) {
            return [];
        }

        $containers = [];
        foreach ($response as $raw) {
            $name = ltrim($raw['Names'][0] ?? 'unknown', '/');

            // Ignorer les containers avec un préfixe hash (ex: f7c87015511b_argos_app)
            // Ce sont des fantômes d'anciennes runs sans container_name explicite
            if (preg_match('/^[a-f0-9]{12}_/', $name)) {
                continue;
            }

            $containers[] = [
                'id'      => substr($raw['Id'] ?? '', 0, 12),
                'name'    => $name,
                'image'   => $raw['Image'] ?? 'unknown',
                'status'  => $raw['Status'] ?? '',   // ex: "Up 2 hours"
                'state'   => $raw['State'] ?? '',    // running, exited, paused, restarting...
                'created' => $raw['Created'] ?? 0,
                'ports'   => $raw['Ports'] ?? [],
            ];
        }

        return $containers;
    }

    /**
     * Retourne les stats CPU + mémoire d'un conteneur.
     * Utilise one-shot=true pour éviter d'attendre 2 mesures (Docker API 1.41+).
     */
    public function getContainerStats(string $containerId): ?array
    {
        $response = $this->request('GET', "/containers/{$containerId}/stats?stream=false&one-shot=true");

        if ($response === null) {
            return null;
        }

        return [
            'cpu_percent' => $this->calculateCpuPercent($response),
            'mem_usage'   => $response['memory_stats']['usage'] ?? 0,
            'mem_limit'   => $response['memory_stats']['limit'] ?? 1,
            'mem_percent' => $this->calculateMemPercent($response),
        ];
    }

    /**
     * Inspecte un container (image, ports, volumes, état détaillé).
     */
    public function inspect(string $nameOrId): ?array
    {
        return $this->request('GET', "/containers/{$nameOrId}/json");
    }

    /**
     * Retourne les derniers logs d'un container (stdout + stderr).
     */
    public function getLogs(string $nameOrId, int $tail = 100): string
    {
        $raw = $this->requestRaw("/containers/{$nameOrId}/logs?stdout=true&stderr=true&tail={$tail}");
        return $raw !== null ? $this->parseDockerLogs($raw) : '';
    }

    /**
     * Exécute une action (start / stop / restart) sur un container.
     */
    public function performAction(string $nameOrId, string $action): bool
    {
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return false;
        }
        return $this->requestAction("/containers/{$nameOrId}/{$action}");
    }

    /**
     * Envoie une requête HTTP au socket Unix Docker.
     */
    private function request(string $method, string $path): ?array
    {
        if (!file_exists(self::SOCKET_PATH)) {
            $this->logger->error('Docker socket introuvable : ' . self::SOCKET_PATH);
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => self::SOCKET_PATH,
            CURLOPT_URL              => 'http://localhost/v1.43' . $path,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_TIMEOUT          => 10,
            CURLOPT_CUSTOMREQUEST    => $method,
            CURLOPT_HTTPHEADER       => ['Content-Type: application/json'],
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("DockerClient curl error: {$error}");
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error("DockerClient HTTP {$httpCode} pour {$path}");
            return null;
        }

        return json_decode($body, true) ?? null;
    }

    /** Requête renvoyant le body brut (logs Docker — stream multiplexé). */
    private function requestRaw(string $path): ?string
    {
        if (!file_exists(self::SOCKET_PATH)) {
            return null;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => self::SOCKET_PATH,
            CURLOPT_URL              => 'http://localhost/v1.43' . $path,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_TIMEOUT          => 10,
            CURLOPT_HTTPHEADER       => ['Content-Type: application/json'],
        ]);
        $body  = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error || $body === false) {
            $this->logger->error("DockerClient raw error: {$error}");
            return null;
        }
        return (string) $body;
    }

    /** POST sans body JSON attendu en retour (start / stop / restart → 204). */
    private function requestAction(string $path): bool
    {
        if (!file_exists(self::SOCKET_PATH)) {
            return false;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => self::SOCKET_PATH,
            CURLOPT_URL              => 'http://localhost/v1.43' . $path,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_TIMEOUT          => 15,
            CURLOPT_CUSTOMREQUEST    => 'POST',
            CURLOPT_POSTFIELDS       => '{}',
            CURLOPT_HTTPHEADER       => ['Content-Type: application/json'],
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($error) {
            $this->logger->error("DockerClient action error: {$error}");
            return false;
        }
        return $httpCode < 400; // 204 (success), 304 (already in state), both = ok
    }

    /**
     * Parse le stream multiplexé Docker (headers 8 octets par chunk).
     * Format : [stream(1)][pad(3)][size big-endian(4)][payload]
     */
    private function parseDockerLogs(string $raw): string
    {
        $output = '';
        $len    = strlen($raw);
        $offset = 0;
        while ($offset + 8 <= $len) {
            $size = (int) unpack('N', substr($raw, $offset + 4, 4))[1];
            if ($size > 0 && $offset + 8 + $size <= $len) {
                $output .= substr($raw, $offset + 8, $size);
            }
            $offset += 8 + $size;
            if ($size === 0) {
                break;
            }
        }
        // Fallback : si le parsing échoue (pas de headers), retourner le raw
        return $output !== '' ? $output : $raw;
    }

    private function calculateCpuPercent(array $stats): float
    {
        $cpuDelta    = ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0)
                     - ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $systemDelta = ($stats['cpu_stats']['system_cpu_usage'] ?? 0)
                     - ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
        $numCpus     = count($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? [1]);

        if ($systemDelta <= 0 || $cpuDelta < 0) {
            return 0.0;
        }

        return round(($cpuDelta / $systemDelta) * $numCpus * 100, 2);
    }

    private function calculateMemPercent(array $stats): float
    {
        $usage = ($stats['memory_stats']['usage'] ?? 0)
               - ($stats['memory_stats']['stats']['cache'] ?? 0);
        $limit = $stats['memory_stats']['limit'] ?? 0;

        if ($limit <= 0) {
            return 0.0;
        }

        return round(($usage / $limit) * 100, 2);
    }
}
