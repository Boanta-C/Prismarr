<?php

namespace App\Service\UniFi;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class UniFiClient
{
    private const SITE = 'default';

    public function __construct(
        #[Autowire(env: 'UNIFI_URL')]      private readonly string $url,
        #[Autowire(env: 'UNIFI_USER')]     private readonly string $user,
        #[Autowire(env: 'UNIFI_PASSWORD')] private readonly string $password,
        private readonly LoggerInterface $logger,
    ) {}

    public function getData(): ?array
    {
        $auth = $this->login();
        if ($auth === null) {
            return null;
        }

        ['token' => $token, 'csrf' => $csrf] = $auth;
        $headers = ['Cookie: TOKEN=' . $token, 'x-csrf-token: ' . $csrf];

        $health  = $this->fetchHealth($headers);
        $ap      = $this->fetchAp($headers);
        $clients = $this->fetchClients($headers);

        $this->logout($headers);

        $wiredCount = count(array_filter($clients, fn($c) => ($c['is_wired'] ?? false)));
        $wifiCount  = count($clients) - $wiredCount;

        return [
            'health'       => $health,
            'ap'           => $ap,
            'clients'      => $clients,
            'client_count' => count($clients),
            'wired_count'  => $wiredCount,
            'wifi_count'   => $wifiCount,
        ];
    }

    // -----------------------------------------------------------------------

    private function login(): ?array
    {
        $response = $this->request('POST', '/api/auth/login', [
            'username' => $this->user,
            'password' => $this->password,
        ], [], true);

        if ($response === null) {
            return null;
        }

        ['headers' => $headers, 'body' => $body] = $response;

        preg_match('/TOKEN=([^;]+)/', $headers, $m);
        $token = $m[1] ?? null;

        if (!$token) {
            $this->logger->error('UniFiClient : TOKEN absent de la réponse login.');
            return null;
        }

        $parts   = explode('.', $token);
        $payload = json_decode(base64_decode(str_pad($parts[1] ?? '', 4 * ceil(strlen($parts[1] ?? '') / 4), '=', STR_PAD_RIGHT)), true);
        $csrf    = $payload['csrfToken'] ?? '';

        return ['token' => $token, 'csrf' => $csrf];
    }

    private function logout(array $headers): void
    {
        $this->request('POST', '/api/auth/logout', [], $headers);
    }

    private function fetchHealth(array $headers): array
    {
        $res    = $this->request('GET', '/proxy/network/api/s/' . self::SITE . '/stat/health', [], $headers);
        $health = [];
        foreach ($res['data'] ?? [] as $item) {
            $health[$item['subsystem']] = $item['status'] ?? 'unknown';
        }
        return $health;
    }

    private function fetchAp(array $headers): ?array
    {
        $res     = $this->request('GET', '/proxy/network/api/s/' . self::SITE . '/stat/device', [], $headers);
        $devices = $res['data'] ?? [];

        if (empty($devices)) {
            return null;
        }

        $ap = $devices[0];

        $radioTable = [];
        foreach ($ap['radio_table_stats'] ?? $ap['radio_table'] ?? [] as $r) {
            $radioTable[] = [
                'name'     => $r['name']     ?? null,
                'radio'    => $r['radio']    ?? null,
                'channel'  => $r['channel']  ?? null,
                'tx_power' => $r['tx_power'] ?? null,
                'num_sta'  => $r['num_sta']  ?? ($r['user-num_sta'] ?? 0),
            ];
        }

        return [
            'name'         => $ap['name']    ?? ($ap['model'] ?? 'UniFi AP'),
            'model'        => $ap['model']   ?? null,
            'mac'          => $ap['mac']     ?? null,
            'ip'           => $ap['ip']      ?? null,
            'uptime'       => $ap['uptime']  ?? 0,
            'tx_bytes'     => $ap['uplink']['tx_bytes'] ?? $ap['tx_bytes'] ?? 0,
            'rx_bytes'     => $ap['uplink']['rx_bytes'] ?? $ap['rx_bytes'] ?? 0,
            'status'       => ($ap['state']  ?? 0) === 1 ? 'up' : 'down',
            'version'      => $ap['version'] ?? null,
            'satisfaction' => $ap['satisfaction'] ?? null,
            'num_sta'      => $ap['user-num_sta']  ?? $ap['num_sta'] ?? null,
            'radio_table'  => $radioTable,
        ];
    }

    private function fetchClients(array $headers): array
    {
        $res     = $this->request('GET', '/proxy/network/api/s/' . self::SITE . '/stat/sta', [], $headers);
        $clients = [];

        foreach ($res['data'] ?? [] as $c) {
            $clients[] = [
                'mac'          => $c['mac']         ?? null,
                'hostname'     => $c['hostname']    ?? $c['name'] ?? $c['mac'] ?? 'unknown',
                'ip'           => $c['ip']           ?? null,
                'is_wired'     => $c['is_wired']     ?? false,
                'rssi'         => $c['rssi']         ?? null,
                'signal'       => $c['signal']       ?? null,
                'tx_bytes'     => $c['tx_bytes']     ?? 0,
                'rx_bytes'     => $c['rx_bytes']     ?? 0,
                'uptime'       => $c['uptime']       ?? 0,
                'oui'          => $c['oui']          ?? null,
                'tx_rate'      => isset($c['tx_rate'])   ? (int) round($c['tx_rate'] / 1000) : null,
                'rx_rate'      => isset($c['rx_rate'])   ? (int) round($c['rx_rate'] / 1000) : null,
                'channel'      => $c['channel']      ?? null,
                'radio_proto'  => $c['radio_proto']  ?? null,
                'satisfaction' => $c['satisfaction'] ?? null,
                'network_name' => $c['network'] ?? $c['network_name'] ?? null,
                'vlan'         => $c['vlan_id']      ?? null,
            ];
        }

        usort($clients, fn($a, $b) => strcmp($a['hostname'], $b['hostname']));

        return $clients;
    }

    // -----------------------------------------------------------------------

    private function request(string $method, string $path, array $body = [], array $headers = [], bool $returnHeaders = false): ?array
    {
        $ch = curl_init();

        $allHeaders = array_merge(['Content-Type: application/json'], $headers);

        $opts = [
            CURLOPT_URL            => rtrim($this->url, '/') . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_HEADER         => $returnHeaders,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $body ? json_encode($body) : '{}';
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("UniFiClient curl error sur {$path}: {$error}");
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error("UniFiClient HTTP {$httpCode} sur {$path}");
            return null;
        }

        if ($returnHeaders) {
            $headerSize = strpos($response, "\r\n\r\n");
            return [
                'headers' => substr($response, 0, $headerSize),
                'body'    => json_decode(substr($response, $headerSize + 4), true) ?? [],
            ];
        }

        return json_decode($response, true) ?? [];
    }
}
