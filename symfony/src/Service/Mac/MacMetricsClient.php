<?php

namespace App\Service\Mac;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Collecte les métriques système du Mac Mini.
 *
 * Mode 1 (si MAC_METRICS_AGENT_URL est défini) :
 *   Interroge l'agent PHP natif qui tourne sur le Mac (hors Docker).
 *   → Vraies métriques macOS : RAM physique, CPU réel, disque interne.
 *
 * Mode 2 (fallback automatique) :
 *   Lit /proc/stat, /proc/meminfo, disk_free_space dans le container.
 *   → Métriques de la VM Linux Docker Desktop (proxy correct pour la charge).
 */
class MacMetricsClient
{
    /** Lecture précédente de /proc/stat pour le calcul CPU delta (mode fallback). */
    private ?array $lastCpuStats = null;

    public function __construct(
        private readonly LoggerInterface    $logger,
        private readonly HttpClientInterface $http,
        private readonly string             $agentUrl,
    ) {}

    // ── API publique ─────────────────────────────────────────────────────────

    public function getMetrics(): array
    {
        if ($this->agentUrl !== '') {
            $data = $this->fetchFromAgent();
            if ($data !== null) {
                return ['agent_active' => true] + $data;
            }
            $this->logger->warning('MacMetricsClient : agent injoignable, fallback /proc.');
        }

        return ['agent_active' => false] + $this->collectFromProc();
    }

    // ── Mode agent HTTP ──────────────────────────────────────────────────────

    private function fetchFromAgent(): ?array
    {
        try {
            $response = $this->http->request('GET', rtrim($this->agentUrl, '/') . '/metrics', [
                'timeout' => 4,
            ]);

            $data = $response->toArray();

            return [
                'cpu'               => (float) ($data['cpu']               ?? 0),
                'mem_percent'       => (float) ($data['mem_percent']       ?? 0),
                'mem_used_mb'       => (int)   ($data['mem_used_mb']       ?? 0),
                'mem_total_mb'      => (int)   ($data['mem_total_mb']      ?? 0),
                'mem_active_mb'     => (int)   ($data['mem_active_mb']     ?? 0),
                'mem_wired_mb'      => (int)   ($data['mem_wired_mb']      ?? 0),
                'mem_compressed_mb' => (int)   ($data['mem_compressed_mb'] ?? 0),
                'mem_inactive_mb'   => (int)   ($data['mem_inactive_mb']   ?? 0),
                'mem_free_mb'       => (int)   ($data['mem_free_mb']       ?? 0),
                'disk_percent'      => (float) ($data['disk_percent']      ?? 0),
                'disk_used_gb'      => (float) ($data['disk_used_gb']      ?? 0),
                'disk_total_gb'     => (float) ($data['disk_total_gb']     ?? 0),
                'disk_rw_mbs'       => (float) ($data['disk_rw_mbs']       ?? 0),
                'net_in_kbs'        => (float) ($data['net_in_kbs']        ?? 0),
                'net_out_kbs'       => (float) ($data['net_out_kbs']       ?? 0),
                'net_interface'     => (string)($data['net_interface']     ?? 'en0'),
                'swap_used_mb'      => (int)   ($data['swap_used_mb']      ?? 0),
                'swap_total_mb'     => (int)   ($data['swap_total_mb']     ?? 0),
                'swap_percent'      => (float) ($data['swap_percent']      ?? 0),
                'uptime'            => (int)   ($data['uptime']            ?? 0),
                'load'              => (array) ($data['load']              ?? []),
                'proc_count'        => (int)   ($data['proc_count']        ?? 0),
                'top_procs'         => (array) ($data['top_procs']         ?? []),
                'cpu_model'         => (string)($data['cpu_model']         ?? ''),
                'physical_cores'    => (int)   ($data['physical_cores']    ?? 0),
                'logical_cores'     => (int)   ($data['logical_cores']     ?? 0),
                'os_name'           => (string)($data['os_name']           ?? ''),
                'os_version'        => (string)($data['os_version']        ?? ''),
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('MacMetricsClient agent error: ' . $e->getMessage());
            return null;
        }
    }

    // ── Mode fallback /proc ──────────────────────────────────────────────────

    private function collectFromProc(): array
    {
        $mem  = $this->readMeminfo();
        $disk = $this->readDisk();

        return [
            'cpu'               => $this->getCpuPercent(),
            'mem_percent'       => $mem['percent'],
            'mem_used_mb'       => $mem['used_mb'],
            'mem_total_mb'      => $mem['total_mb'],
            'mem_active_mb'     => 0,
            'mem_wired_mb'      => 0,
            'mem_compressed_mb' => 0,
            'mem_inactive_mb'   => 0,
            'mem_free_mb'       => 0,
            'disk_percent'      => $disk['percent'],
            'disk_used_gb'      => $disk['used_gb'],
            'disk_total_gb'     => $disk['total_gb'],
            'disk_rw_mbs'       => 0.0,
            'net_in_kbs'        => 0.0,
            'net_out_kbs'       => 0.0,
            'net_interface'     => '',
            'swap_used_mb'      => 0,
            'swap_total_mb'     => 0,
            'swap_percent'      => 0.0,
            'uptime'            => $this->readUptime(),
            'load'              => $this->readLoadAvg(),
            'proc_count'        => 0,
            'top_procs'         => [],
            'cpu_model'         => '',
            'physical_cores'    => 0,
            'logical_cores'     => 0,
            'os_name'           => '',
            'os_version'        => '',
        ];
    }

    private function getCpuPercent(): float
    {
        $current = $this->readCpuStats();
        if ($current === null) {
            return 0.0;
        }

        if ($this->lastCpuStats === null) {
            $this->lastCpuStats = $current;
            return 0.0;
        }

        $totalDelta = $current['total'] - $this->lastCpuStats['total'];
        $idleDelta  = $current['idle']  - $this->lastCpuStats['idle'];
        $this->lastCpuStats = $current;

        return $totalDelta > 0 ? round((1 - $idleDelta / $totalDelta) * 100, 1) : 0.0;
    }

    private function readCpuStats(): ?array
    {
        if (!is_readable('/proc/stat')) {
            return null;
        }
        $line   = explode("\n", file_get_contents('/proc/stat'))[0];
        $parts  = preg_split('/\s+/', trim($line));
        if (count($parts) < 5) {
            return null;
        }
        $values = array_map('intval', array_slice($parts, 1));
        return [
            'total' => array_sum($values),
            'idle'  => $values[3] + ($values[4] ?? 0),
        ];
    }

    private function readMeminfo(): array
    {
        $default = ['percent' => 0.0, 'used_mb' => 0, 'total_mb' => 0];
        if (!is_readable('/proc/meminfo')) {
            return $default;
        }
        $data = [];
        foreach (explode("\n", file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $data[$m[1]] = (int) $m[2];
            }
        }
        $total     = $data['MemTotal']     ?? 0;
        $available = $data['MemAvailable'] ?? ($data['MemFree'] ?? 0);
        if ($total <= 0) {
            return $default;
        }
        $used = $total - $available;
        return [
            'percent'  => round($used / $total * 100, 1),
            'used_mb'  => (int) round($used / 1024),
            'total_mb' => (int) round($total / 1024),
        ];
    }

    private function readDisk(): array
    {
        $total = @disk_total_space('/');
        $free  = @disk_free_space('/');
        if (!$total || $total <= 0) {
            return ['percent' => 0.0, 'used_gb' => 0.0, 'total_gb' => 0.0];
        }
        $used = $total - $free;
        return [
            'percent'  => round($used / $total * 100, 1),
            'used_gb'  => round($used  / 1024 ** 3, 1),
            'total_gb' => round($total / 1024 ** 3, 1),
        ];
    }

    private function readUptime(): int
    {
        return is_readable('/proc/uptime')
            ? (int) explode(' ', file_get_contents('/proc/uptime'))[0]
            : 0;
    }

    private function readLoadAvg(): array
    {
        if (!is_readable('/proc/loadavg')) {
            return [];
        }
        $parts = explode(' ', file_get_contents('/proc/loadavg'));
        return [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
    }
}
