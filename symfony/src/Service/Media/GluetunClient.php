<?php

namespace App\Service\Media;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Client pour l'API de contrôle Gluetun (HTTP Control Server).
 * Gluetun héberge qBittorrent derrière son VPN — on interroge son API pour
 * récupérer l'IP publique VPN + l'état de connexion.
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

    public function __construct(
        #[Autowire(env: 'GLUETUN_URL')]      private readonly string $baseUrl,
        #[Autowire(env: 'GLUETUN_API_KEY')]  private readonly string $apiKey,
        /** 'openvpn', 'wireguard' ou '' pour auto (essaie les deux). */
        #[Autowire(env: 'GLUETUN_PROTOCOL')] private readonly string $protocol,
        private readonly LoggerInterface $logger,
    ) {}

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
