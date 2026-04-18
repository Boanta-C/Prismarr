<?php

namespace App\Service\Synology;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SynologyClient
{
    public function __construct(
        #[Autowire(env: 'SYNOLOGY_URL')]            private readonly string $baseUrl,
        #[Autowire(env: 'SYNOLOGY_USER')]           private readonly string $user,
        #[Autowire(env: 'SYNOLOGY_PASSWORD')]       private readonly string $password,
        #[Autowire(env: 'NAS_DOCKER_PROXY_URL')]    private readonly string $dockerProxyUrl,
        private readonly LoggerInterface $logger,
    ) {}

    public function getSystemData(): ?array
    {
        $sid = $this->login();
        if ($sid === null) {
            return null;
        }

        try {
            $utilization = $this->getUtilization($sid);
            $systemInfo  = $this->getSystemInfo($sid);
            $storage     = $this->getStorage($sid);
            $docker      = $this->getDockerContainers($sid);
            $adapters    = $this->getNetworkAdapters($sid);
        } finally {
            $this->logout($sid);
        }

        if ($utilization === null) {
            return null;
        }

        return [
            'cpu'          => $utilization['cpu'],
            'mem'          => $utilization['mem'],
            'mem_total_mb' => $utilization['mem_total_mb'],
            'mem_used_mb'  => $utilization['mem_used_mb'],
            'network'      => $utilization['network'],
            'temp'         => $systemInfo['temp'],
            'model'        => $systemInfo['model'],
            'firmware'     => $systemInfo['firmware'],
            'serial'       => $systemInfo['serial'],
            'uptime'       => $systemInfo['uptime'],
            'ram_size_mb'  => $systemInfo['ram_size_mb'],
            'cpu_info'     => $systemInfo['cpu_info'],
            'volumes'      => $storage['volumes'],
            'disks'        => $storage['disks'],
            'docker'       => $docker,
            'adapters'     => $adapters,
        ];
    }

    // -------------------------------------------------------------------------

    private function login(): ?string
    {
        $params = http_build_query([
            'api'      => 'SYNO.API.Auth',
            'version'  => '7',
            'method'   => 'login',
            'account'  => $this->user,
            'passwd'   => $this->password,
            'session'  => 'ArgosAPI',
            'format'   => 'sid',
        ]);

        $response = $this->request('POST', '/webapi/auth.cgi', $params);

        if ($response === null || !($response['success'] ?? false)) {
            $this->logger->error('SynologyClient : échec de login', [
                'error' => $response['error'] ?? 'réponse nulle',
            ]);
            return null;
        }

        return $response['data']['sid'] ?? null;
    }

    private function logout(string $sid): void
    {
        $params = http_build_query([
            'api'     => 'SYNO.API.Auth',
            'version' => '7',
            'method'  => 'logout',
            'session' => 'ArgosAPI',
            '_sid'    => $sid,
        ]);
        $this->request('GET', '/webapi/auth.cgi?' . $params);
    }

    private function getUtilization(string $sid): ?array
    {
        $params = http_build_query([
            'api'     => 'SYNO.Core.System.Utilization',
            'version' => '1',
            'method'  => 'get',
            '_sid'    => $sid,
        ]);

        $response = $this->request('GET', '/webapi/entry.cgi?' . $params);

        if ($response === null || !($response['success'] ?? false)) {
            $this->logger->warning('SynologyClient : impossible de récupérer l\'utilisation système.');
            return null;
        }

        $data = $response['data'] ?? [];
        $cpu  = $data['cpu'] ?? [];
        $mem  = $data['memory'] ?? [];

        $cpuPercent = ($cpu['user_load'] ?? 0) + ($cpu['system_load'] ?? 0) + ($cpu['other_load'] ?? 0);
        $memPercent = (float) ($mem['real_usage'] ?? 0);
        $totalKb    = (int) ($mem['total_real'] ?? 0);
        $availKb    = (int) ($mem['avail_real'] ?? 0);
        $usedKb     = max(0, $totalKb - $availKb);

        $network = [];
        foreach ($data['network'] ?? [] as $iface) {
            if (!isset($iface['device'])) continue;
            $network[] = [
                'device' => $iface['device'],
                'tx'     => (int) ($iface['tx'] ?? 0),
                'rx'     => (int) ($iface['rx'] ?? 0),
            ];
        }

        return [
            'cpu'          => (float) $cpuPercent,
            'mem'          => $memPercent,
            'mem_total_mb' => (int) round($totalKb / 1024),
            'mem_used_mb'  => (int) round($usedKb  / 1024),
            'network'      => $network,
        ];
    }

    private function getSystemInfo(string $sid): array
    {
        $params = http_build_query([
            'api'     => 'SYNO.Core.System',
            'version' => '1',
            'method'  => 'info',
            '_sid'    => $sid,
        ]);

        $response = $this->request('GET', '/webapi/entry.cgi?' . $params);

        if ($response === null || !($response['success'] ?? false)) {
            return ['temp' => null, 'model' => null, 'firmware' => null, 'serial' => null, 'uptime' => null];
        }

        $data = $response['data'] ?? [];

        $cpuInfo = trim(($data['cpu_vendor'] ?? '') . ' ' . ($data['cpu_series'] ?? ''));

        return [
            'temp'        => isset($data['sys_temp']) ? (float) $data['sys_temp'] : null,
            'model'       => $data['model'] ?? null,
            'firmware'    => $data['firmware_ver'] ?? null,
            'serial'      => $data['serial'] ?? null,
            'uptime'      => $data['up_time'] ?? null,
            'ram_size_mb' => isset($data['ram_size']) ? (int) $data['ram_size'] : null,
            'cpu_info'    => $cpuInfo !== '' ? $cpuInfo : null,
        ];
    }

    private function getStorage(string $sid): array
    {
        $params = http_build_query([
            'api'     => 'SYNO.Storage.CGI.Storage',
            'version' => '1',
            'method'  => 'load_info',
            '_sid'    => $sid,
        ]);

        $response = $this->request('GET', '/webapi/entry.cgi?' . $params);

        if ($response === null || !($response['success'] ?? false)) {
            $this->logger->warning('SynologyClient : impossible de récupérer le stockage.');
            return ['volumes' => [], 'disks' => []];
        }

        $volumes = [];
        foreach (($response['data']['volumes'] ?? []) as $raw) {
            $size       = $raw['size'] ?? [];
            $totalBytes = (float) ($size['total'] ?? 0);
            $usedBytes  = (float) ($size['used']  ?? 0);
            $percent    = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 2) : 0.0;
            $volId      = ltrim($raw['vol_path'] ?? '/volume', '/');

            $volumes[] = [
                'id'       => $volId,
                'used_gb'  => round($usedBytes / 1024 ** 3, 1),
                'total_gb' => round($totalBytes / 1024 ** 3, 1),
                'percent'  => $percent,
                'status'   => $raw['status'] ?? 'unknown',
            ];
        }

        $disks = [];
        foreach (($response['data']['disks'] ?? []) as $raw) {
            // DSM 7 = size_total (bytes), DSM 6 = size (bytes)
            $sizeBytes = (float) ($raw['size_total'] ?? $raw['size'] ?? 0);
            $disks[] = [
                'id'           => $raw['id']    ?? 'unknown',
                'model'        => $raw['model'] ?? null,
                'temp'         => isset($raw['temp']) ? (int) $raw['temp'] : null,
                'status'       => $raw['status'] ?? 'unknown',
                'size_gb'      => $sizeBytes > 0 ? (int) round($sizeBytes / 1024 ** 3) : null,
                'type'         => $raw['diskType'] ?? $raw['type'] ?? null,
                'smart_status' => $raw['smart_status'] ?? $raw['ata_smart_status'] ?? null,
                'firm'         => $raw['firm'] ?? null,
            ];
        }

        return ['volumes' => $volumes, 'disks' => $disks];
    }

    private function getDockerContainers(string $sid): array
    {
        // Via Docker Remote API (docker-socket-proxy sur le NAS)
        if ($this->dockerProxyUrl !== '') {
            return $this->getDockerContainersViaRemoteApi();
        }

        return [];
    }

    public function getAllContainerStats(): array
    {
        $url = rtrim($this->dockerProxyUrl, '/') . '/containers/json';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $code < 200 || $code >= 300) {
            return [];
        }

        $containers = json_decode($body, true) ?? [];
        if (empty($containers)) {
            return [];
        }

        $ids = array_map(fn($c) => substr($c['Id'] ?? '', 0, 12), $containers);

        return $this->getContainerStatsByIds($ids);
    }

    /**
     * Stats CPU/RAM pour une liste d'IDs (ou noms) de containers, en parallèle.
     * Utilisé directement par le worker pour éviter un double appel liste.
     *
     * @param string[] $ids IDs courts (12 chars) ou noms des containers
     */
    public function getContainerStatsByIds(array $ids): array
    {
        if (empty($ids) || $this->dockerProxyUrl === '') {
            return [];
        }

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($ids as $id) {
            $statsUrl = rtrim($this->dockerProxyUrl, '/') . '/containers/' . $id . '/stats?stream=false';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $statsUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
            ]);
            $handles[$id] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        $active = null;
        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active > 0);

        $stats = [];
        foreach ($handles as $id => $ch) {
            $data = json_decode(curl_multi_getcontent($ch), true);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($data) {
                $stats[$id] = $this->parseContainerStats($data);
            }
        }
        curl_multi_close($mh);

        return $stats;
    }

    public function getContainerDetails(string $id): ?array
    {
        $url = rtrim($this->dockerProxyUrl, '/') . '/containers/' . $id . '/json';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $code < 200 || $code >= 300) {
            $this->logger->error("SynologyClient : container details inaccessible ({$code}) {$error}");
            return null;
        }

        return json_decode($body, true) ?? null;
    }

    public function getContainerLogs(string $id): string
    {
        $url = rtrim($this->dockerProxyUrl, '/') . '/containers/' . $id . '/logs?stdout=1&stderr=1&tail=200&timestamps=0';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $code < 200 || $code >= 300) {
            return '';
        }

        return $this->stripDockerLogHeaders($body ?: '');
    }

    public function containerAction(string $id, string $action): bool
    {
        $url = rtrim($this->dockerProxyUrl, '/') . '/containers/' . $id . '/' . $action;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
        ]);
        curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("SynologyClient : action '{$action}' container {$id} failed: {$error}");
            return false;
        }

        return $code >= 200 && $code < 300;
    }

    private function parseContainerStats(array $data): array
    {
        $cpuDelta    = ($data['cpu_stats']['cpu_usage']['total_usage'] ?? 0)
                     - ($data['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $systemDelta = ($data['cpu_stats']['system_cpu_usage'] ?? 0)
                     - ($data['precpu_stats']['system_cpu_usage'] ?? 0);
        $numCpus     = $data['cpu_stats']['online_cpus']
                    ?? count($data['cpu_stats']['cpu_usage']['percpu_usage'] ?? [0]);
        if ($numCpus < 1) {
            $numCpus = 1;
        }
        $cpuPercent = ($systemDelta > 0) ? round(($cpuDelta / $systemDelta) * $numCpus * 100, 1) : 0.0;

        $memUsage   = $data['memory_stats']['usage'] ?? 0;
        $memCache   = $data['memory_stats']['stats']['cache'] ?? 0;
        $memReal    = max(0, $memUsage - $memCache);
        $memLimit   = $data['memory_stats']['limit'] ?? 0;
        $memPercent = ($memLimit > 0) ? round(($memReal / $memLimit) * 100, 1) : 0.0;
        $memMb      = (int) round($memReal / 1024 / 1024);

        return [
            'cpu'    => $cpuPercent,
            'mem'    => $memPercent,
            'mem_mb' => $memMb,
        ];
    }

    private function stripDockerLogHeaders(string $raw): string
    {
        $result = '';
        $pos    = 0;
        $len    = strlen($raw);

        while ($pos + 8 <= $len) {
            $size = unpack('N', substr($raw, $pos + 4, 4))[1];
            $pos += 8;
            if ($size === 0) {
                continue;
            }
            if ($pos + $size > $len) {
                $result .= substr($raw, $pos);
                break;
            }
            $result .= substr($raw, $pos, $size);
            $pos   += $size;
        }

        return $result;
    }

    private function getDockerContainersViaRemoteApi(): array
    {
        $url = rtrim($this->dockerProxyUrl, '/') . '/containers/json?all=1';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);

        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $code < 200 || $code >= 300) {
            $this->logger->error("SynologyClient : Docker Remote API inaccessible ({$code}) {$error}");
            return [];
        }

        $raw = json_decode($body, true) ?? [];
        $containers = [];

        foreach ($raw as $c) {
            $name       = ltrim($c['Names'][0] ?? 'unknown', '/');
            $state      = strtolower($c['State'] ?? 'unknown');
            $statusRaw  = (string) ($c['Status'] ?? '');
            $createdTs  = (int) ($c['Created'] ?? 0);
            $exitCode   = null;
            if (preg_match('/Exited \((-?\d+)\)/', $statusRaw, $m)) {
                $exitCode = (int) $m[1];
            }
            $containers[] = [
                'id'         => substr($c['Id'] ?? '', 0, 12),
                'name'       => $name,
                'image'      => $c['Image'] ?? null,
                'state'      => $state,
                'status'     => $this->computeContainerStatus($state, $exitCode, $createdTs),
                'status_raw' => $statusRaw,
                'exit_code'  => $exitCode,
                'created'    => $createdTs,
            ];
        }

        usort($containers, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $containers;
    }

    /**
     * Traduit le State Docker brut en statut Argos : up / down / error / unknown.
     * - running       → up
     * - exited + 0    → down (arrêt propre)
     * - exited + !=0  → error (crash)
     * - created > 2min → error (container coincé au démarrage)
     * - dead / restarting → error
     * - paused        → down
     */
    private function computeContainerStatus(string $state, ?int $exitCode, int $createdTs): string
    {
        // Exit codes d'arrêt volontaire : 0 (clean), 143 (SIGTERM via docker stop), 137 (SIGKILL)
        $cleanExit = [0, 137, 143];
        return match ($state) {
            'running'            => 'up',
            'paused'             => 'down',
            'exited'             => ($exitCode !== null && !in_array($exitCode, $cleanExit, true)) ? 'error' : 'down',
            'dead', 'restarting' => 'error',
            'created'            => ($createdTs > 0 && (time() - $createdTs) > 120) ? 'error' : 'down',
            default              => 'unknown',
        };
    }

    private function getNetworkAdapters(string $sid): array
    {
        $params = http_build_query([
            'api'     => 'SYNO.Core.Network.Adapter',
            'version' => '1',
            'method'  => 'list',
            '_sid'    => $sid,
        ]);

        $response = $this->request('GET', '/webapi/entry.cgi?' . $params);

        if ($response === null || !($response['success'] ?? false)) {
            return [];
        }

        $adapters = [];
        foreach ($response['data'] ?? [] as $a) {
            if (!isset($a['id'])) {
                continue;
            }
            $adapters[] = [
                'id'         => $a['id'],
                'ip'         => $a['ip'] ?? null,
                'link_speed' => isset($a['link_speed']) ? (int) $a['link_speed'] : null,
                'status'     => $a['status'] ?? 'unknown',
                'use_dhcp'   => $a['use_dhcp'] ?? false,
                'mac'        => $a['mac'] ?? null,
            ];
        }

        return $adapters;
    }

    // -------------------------------------------------------------------------

    private function request(string $method, string $path, ?string $postBody = null): ?array
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        if ($method === 'POST' && $postBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }

        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("SynologyClient curl error : {$error}");
            return null;
        }

        if ($code < 200 || $code >= 300) {
            $this->logger->error("SynologyClient HTTP {$code} pour {$path}");
            return null;
        }

        return json_decode($body, true) ?? null;
    }
}
