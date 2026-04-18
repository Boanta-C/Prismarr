<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use Psr\Log\LoggerInterface;

/**
 * Client pour l'API de contrôle Gluetun (HTTP Control Server).
 * Service optionnel — si `gluetun.url` n'est pas configuré, les appels retournent null
 * sans exception (Gluetun n'est pas obligatoire pour utiliser Prismarr).
 * Doc : https://github.com/qdm12/gluetun-wiki/blob/main/setup/advanced/control-server.md
 */
class GluetunClient
{
    /** Cache court pour /publicip/ip (change rarement, mais requête légère). */
    private ?array $publicIpCache = null;
    private float  $publicIpCacheAt = 0.0;
    private const PUBLIC_IP_TTL = 30.0;

    /** Cache status VPN (running/stopped) — stable entre reconnexions VPN. */
    private ?string $statusCache = null;
    private float   $statusCacheAt = 0.0;
    private const STATUS_TTL = 10.0;

    /** Cache port forwarded (peut changer à chaque reconnexion VPN mais rare). */
    private ?int $portCache = null;
    private float $portCacheAt = 0.0;
    private const PORT_TTL = 10.0;

    private ?string $baseUrl = null;
    private string $apiKey = '';
    private string $protocol = '';
    private bool $configLoaded = false;

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    private function ensureConfig(): void
    {
        if ($this->configLoaded) return;
        $this->baseUrl  = $this->config->get('gluetun_url');
        $this->apiKey   = $this->config->get('gluetun_api_key') ?? '';
        $this->protocol = $this->config->get('gluetun_protocol') ?? '';
        $this->configLoaded = true;
    }

    /**
     * Retourne IP publique + localisation + organization (provider VPN).
     * Format : { public_ip, region, country, city, organization, ... }
     */
    public function getPublicIp(): ?array
    {
        $now = microtime(true);
        if ($this->publicIpCache !== null && ($now - $this->publicIpCacheAt) < self::PUBLIC_IP_TTL) {
            return $this->publicIpCache;
        }

        $data = $this->get('/v1/publicip/ip');
        if ($data === null) return $this->publicIpCache;

        $this->publicIpCache   = $data;
        $this->publicIpCacheAt = $now;
        return $data;
    }

    /**
     * Status du VPN — 'running', 'stopped', 'crashed'.
     * Utilise /v1/vpn/status (protocol-agnostic, Gluetun v3.40+) puis fallback protocol-specific.
     * Cache 10s (évite jusqu'à 3 requêtes cURL séquentielles à chaque /api/vpn).
     */
    public function getVpnStatus(): ?string
    {
        $now = microtime(true);
        if ($this->statusCache !== null && ($now - $this->statusCacheAt) < self::STATUS_TTL) {
            return $this->statusCache;
        }

        $this->ensureConfig();
        $fallback = match (strtolower($this->protocol)) {
            'openvpn'   => ['/v1/openvpn/status'],
            'wireguard' => ['/v1/wireguard/status'],
            default     => ['/v1/openvpn/status', '/v1/wireguard/status'],
        };
        foreach (array_merge(['/v1/vpn/status'], $fallback) as $path) {
            $data = $this->get($path);
            if ($data !== null && isset($data['status'])) {
                $this->statusCache   = (string)$data['status'];
                $this->statusCacheAt = $now;
                return $this->statusCache;
            }
        }
        return $this->statusCache;
    }

    /**
     * Port forwarded par le provider VPN (celui que Gluetun doit pousser vers qBit via port-update).
     * Gluetun v3.40+ expose /v1/portforward (protégé par défaut — config HTTP_CONTROL_SERVER_AUTH_CONFIG_FILEPATH requise).
     * Fallback sur les anciens /v1/openvpn/portforwarded et /v1/wireguard/portforwarded selon GLUETUN_PROTOCOL.
     * Cache 10s.
     */
    public function getForwardedPort(): ?int
    {
        $now = microtime(true);
        if ($this->portCache !== null && ($now - $this->portCacheAt) < self::PORT_TTL) {
            return $this->portCache;
        }

        $this->ensureConfig();
        $legacy = match (strtolower($this->protocol)) {
            'openvpn'   => ['/v1/openvpn/portforwarded'],
            'wireguard' => ['/v1/wireguard/portforwarded'],
            default     => ['/v1/openvpn/portforwarded', '/v1/wireguard/portforwarded'],
        };
        foreach (array_merge(['/v1/portforward'], $legacy) as $path) {
            $data = $this->get($path);
            if ($data !== null && isset($data['port'])) {
                $this->portCache   = (int)$data['port'];
                $this->portCacheAt = $now;
                return $this->portCache;
            }
        }
        return $this->portCache;
    }

    /**
     * Aggregat complet prêt pour la UI.
     */
    public function getSummary(): array
    {
        $ip     = $this->getPublicIp();
        $status = $this->getVpnStatus();
        $port   = $this->getForwardedPort();
        return [
            'ok'             => $ip !== null,
            'status'         => $status,
            'public_ip'      => $ip['public_ip'] ?? null,
            'country'        => $ip['country'] ?? null,
            'city'           => $ip['city'] ?? null,
            'region'         => $ip['region'] ?? null,
            'organization'   => $ip['organization'] ?? null,
            'timezone'       => $ip['timezone'] ?? null,
            'forwarded_port' => $port,
        ];
    }

    private function get(string $path): ?array
    {
        $this->ensureConfig();
        if ($this->baseUrl === null || $this->baseUrl === '') {
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . $path;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true, // /v1/openvpn/portforwarded redirige vers /v1/portforward
        ];
        if ($this->apiKey !== '') {
            $opts[CURLOPT_HTTPHEADER] = ['Authorization: Bearer ' . $this->apiKey];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            $this->logger->debug('GluetunClient GET failed', ['path' => $path, 'code' => $code]);
            return null;
        }
        return json_decode($body, true) ?: null;
    }
}
