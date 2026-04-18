<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use Psr\Log\LoggerInterface;

class QBittorrentClient
{
    private const SERVICE = 'qBittorrent';

    /** Session qBittorrent réutilisée entre appels (évite un curl POST auth par méthode). */
    private ?string $sid = null;

    /** Cache court pour server_state (alltime_dl/ul change lentement, /sync/maindata est coûteux). */
    private ?array $serverStateCache = null;
    private float $serverStateCacheAt = 0.0;
    private const SERVER_STATE_TTL = 10.0; // secondes

    private string $baseUrl = '';
    private string $user = '';
    private string $password = '';

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    private function ensureConfig(): void
    {
        if ($this->baseUrl === '') {
            $this->baseUrl  = $this->config->require('qbittorrent_url', self::SERVICE);
            $this->user     = $this->config->require('qbittorrent_user', self::SERVICE);
            $this->password = $this->config->require('qbittorrent_password', self::SERVICE);
        }
    }

    /** Ping léger — true si qBit répond et accepte les credentials. */
    public function ping(): bool
    {
        try {
            return $this->getVersion() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Torrents — Lecture
    // ══════════════════════════════════════════════════════════════════════════

    public function getTorrents(string $filter = 'all', ?string $category = null, ?string $tag = null, ?string $sort = 'added_on', bool $reverse = true): array
    {
        $params = ['filter' => $filter, 'sort' => $sort, 'reverse' => $reverse ? 'true' : 'false'];
        if ($category !== null) $params['category'] = $category;
        if ($tag !== null) $params['tag'] = $tag;

        $sid  = $this->login();
        $data = $this->get('/api/v2/torrents/info', $params, $sid);
        if ($data === null) return [];

        return array_map(fn($t) => $this->normalizeTorrent($t), $data);
    }

    public function getTorrentProperties(string $hash): ?array
    {
        $sid  = $this->login();
        $data = $this->get('/api/v2/torrents/properties', ['hash' => $hash], $sid);
        return $data;
    }

    public function getTorrentFiles(string $hash): array
    {
        $sid  = $this->login();
        $data = $this->get('/api/v2/torrents/files', ['hash' => $hash], $sid);
        return $data ?? [];
    }

    public function getTorrentTrackers(string $hash): array
    {
        $sid  = $this->login();
        $data = $this->get('/api/v2/torrents/trackers', ['hash' => $hash], $sid);
        return $data ?? [];
    }

    public function getTorrentPeers(string $hash): array
    {
        $sid  = $this->login();
        $data = $this->get('/api/v2/sync/torrentPeers', ['hash' => $hash], $sid);
        if ($data === null) return [];

        $peers = [];
        foreach (($data['peers'] ?? []) as $peer) {
            $peers[] = [
                'ip'         => $peer['ip'] ?? '',
                'port'       => $peer['port'] ?? 0,
                'client'     => $peer['client'] ?? '',
                'country'    => $peer['country'] ?? '',
                'country_code' => $peer['country_code'] ?? '',
                'progress'   => round(($peer['progress'] ?? 0) * 100, 1),
                'dl_speed'   => $peer['dl_speed'] ?? 0,
                'up_speed'   => $peer['up_speed'] ?? 0,
                'downloaded' => $peer['downloaded'] ?? 0,
                'uploaded'   => $peer['uploaded'] ?? 0,
                'flags'      => $peer['flags'] ?? '',
                'connection' => $peer['connection'] ?? '',
            ];
        }
        return $peers;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Torrents — Actions
    // ══════════════════════════════════════════════════════════════════════════

    public function pauseTorrents(array $hashes): bool
    {
        // qBittorrent 5.0+ renomme pause → stop (ancien endpoint /pause retourne 404 sur WebAPI 2.11+)
        return $this->postAction('/api/v2/torrents/stop', ['hashes' => implode('|', $hashes)]);
    }

    public function resumeTorrents(array $hashes): bool
    {
        // qBittorrent 5.0+ renomme resume → start
        return $this->postAction('/api/v2/torrents/start', ['hashes' => implode('|', $hashes)]);
    }

    public function deleteTorrents(array $hashes, bool $deleteFiles = false): bool
    {
        return $this->postAction('/api/v2/torrents/delete', [
            'hashes'      => implode('|', $hashes),
            'deleteFiles' => $deleteFiles ? 'true' : 'false',
        ]);
    }

    public function recheckTorrents(array $hashes): bool
    {
        return $this->postAction('/api/v2/torrents/recheck', ['hashes' => implode('|', $hashes)]);
    }

    public function reannounceTorrents(array $hashes): bool
    {
        return $this->postAction('/api/v2/torrents/reannounce', ['hashes' => implode('|', $hashes)]);
    }

    public function setForceStart(array $hashes, bool $value = true): bool
    {
        return $this->postAction('/api/v2/torrents/setForceStart', [
            'hashes' => implode('|', $hashes),
            'value'  => $value ? 'true' : 'false',
        ]);
    }

    public function setTorrentCategory(array $hashes, string $category): bool
    {
        return $this->postAction('/api/v2/torrents/setCategory', [
            'hashes'   => implode('|', $hashes),
            'category' => $category,
        ]);
    }

    // @todo Tags par torrent : non exposés côté UI (filtre tag OK, édition à faire dans page Paramètres)
    public function addTorrentTags(array $hashes, array $tags): bool
    {
        return $this->postAction('/api/v2/torrents/addTags', [
            'hashes' => implode('|', $hashes),
            'tags'   => implode(',', $tags),
        ]);
    }

    public function removeTorrentTags(array $hashes, array $tags): bool
    {
        return $this->postAction('/api/v2/torrents/removeTags', [
            'hashes' => implode('|', $hashes),
            'tags'   => implode(',', $tags),
        ]);
    }

    public function setTorrentDownloadLimit(array $hashes, int $limit): bool
    {
        return $this->postAction('/api/v2/torrents/setDownloadLimit', [
            'hashes' => implode('|', $hashes),
            'limit'  => (string)$limit,
        ]);
    }

    public function setTorrentUploadLimit(array $hashes, int $limit): bool
    {
        return $this->postAction('/api/v2/torrents/setUploadLimit', [
            'hashes' => implode('|', $hashes),
            'limit'  => (string)$limit,
        ]);
    }

    public function setFilePriority(string $hash, array $fileIds, int $priority): bool
    {
        return $this->postAction('/api/v2/torrents/filePrio', [
            'hash'     => $hash,
            'id'       => implode('|', $fileIds),
            'priority' => (string)$priority,
        ]);
    }

    public function toggleSequentialDownload(array $hashes): bool
    {
        return $this->postAction('/api/v2/torrents/toggleSequentialDownload', [
            'hashes' => implode('|', $hashes),
        ]);
    }

    public function toggleFirstLastPiecePrio(array $hashes): bool
    {
        return $this->postAction('/api/v2/torrents/toggleFirstLastPiecePrio', [
            'hashes' => implode('|', $hashes),
        ]);
    }

    // @todo Priorités torrent : non exposées côté UI, réservées à la future page Paramètres qBit
    public function increasePriority(array $hashes): bool
    {
        return $this->postAction('/api/v2/torrents/increasePrio', ['hashes' => implode('|', $hashes)]);
    }

    public function decreasePriority(array $hashes): bool
    {
        return $this->postAction('/api/v2/torrents/decreasePrio', ['hashes' => implode('|', $hashes)]);
    }

    public function topPriority(array $hashes): bool
    {
        return $this->postAction('/api/v2/torrents/topPrio', ['hashes' => implode('|', $hashes)]);
    }

    public function bottomPriority(array $hashes): bool
    {
        return $this->postAction('/api/v2/torrents/bottomPrio', ['hashes' => implode('|', $hashes)]);
    }

    public function renameTorrent(string $hash, string $name): bool
    {
        return $this->postAction('/api/v2/torrents/rename', ['hash' => $hash, 'name' => $name]);
    }

    public function setTorrentLocation(string $hash, string $location): bool
    {
        return $this->postAction('/api/v2/torrents/setLocation', ['hashes' => $hash, 'location' => $location]);
    }

    // @todo Options avancées par torrent : super-seeding, auto-management — future page Paramètres
    public function setSuperSeeding(array $hashes, bool $value = true): bool
    {
        return $this->postAction('/api/v2/torrents/setSuperSeeding', [
            'hashes' => implode('|', $hashes),
            'value'  => $value ? 'true' : 'false',
        ]);
    }

    public function setAutoManagement(array $hashes, bool $enable = true): bool
    {
        return $this->postAction('/api/v2/torrents/setAutoManagement', [
            'hashes' => implode('|', $hashes),
            'enable' => $enable ? 'true' : 'false',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Ajout de torrent
    // ══════════════════════════════════════════════════════════════════════════

    public function addTorrentFromUrl(string $urls, ?string $category = null, ?string $savepath = null, bool $paused = false): bool
    {
        $params = ['urls' => $urls];
        if ($category !== null) $params['category'] = $category;
        if ($savepath !== null) $params['savepath'] = $savepath;
        if ($paused) $params['paused'] = 'true';

        return $this->postAction('/api/v2/torrents/add', $params);
    }

    /**
     * Ajoute un ou plusieurs fichiers .torrent à qBit via multipart/form-data.
     * @param array<array{content: string, name: string}> $files
     */
    public function addTorrentFromFiles(array $files, ?string $category = null, ?string $savepath = null, bool $paused = false): bool
    {
        if (empty($files)) return false;
        $sid = $this->login();
        if (!$sid) return false;

        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . '/api/v2/torrents/add';

        $postFields = [];
        if ($category !== null) $postFields['category'] = $category;
        if ($savepath !== null) $postFields['savepath'] = $savepath;
        if ($paused)            $postFields['paused']   = 'true';

        $tmpPaths = [];
        try {
            // Support multi-fichiers : name[0]=... ou nom unique
            foreach ($files as $i => $file) {
                $tmpPath = tempnam(sys_get_temp_dir(), 'qbt_');
                if ($tmpPath === false) continue;
                $tmpPaths[] = $tmpPath;
                file_put_contents($tmpPath, $file['content']);
                $postFields['torrents' . (count($files) > 1 ? "[$i]" : '')] = new \CURLFile($tmpPath, 'application/x-bittorrent', $file['name']);
            }

            // Guard : si tous les tempnam() ont échoué (disque plein, perms), on abort proprement
            if (empty($tmpPaths)) {
                $this->logger->error('QBittorrentClient addTorrentFromFiles: tempnam failed for all files');
                return false;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_HTTPHEADER     => ['Cookie: SID=' . $sid],
            ]);
            $response = curl_exec($ch);
            $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $code !== 200) {
                $this->logger->warning('QBittorrentClient addTorrentFromFiles failed', ['code' => $code]);
                return false;
            }
            // qBit renvoie "Ok." en 200, ou "Fails." si échec
            return stripos((string)$response, 'fail') === false;
        } finally {
            // Nettoyage garanti même en cas d'exception
            foreach ($tmpPaths as $tmp) {
                if (is_file($tmp)) @unlink($tmp);
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Catégories & Tags
    // ══════════════════════════════════════════════════════════════════════════

    public function getCategories(): array
    {
        $sid  = $this->login();
        $data = $this->get('/api/v2/torrents/categories', [], $sid);
        return $data ?? [];
    }

    public function getTags(): array
    {
        $sid  = $this->login();
        $data = $this->get('/api/v2/torrents/tags', [], $sid);
        return $data ?? [];
    }

    // @todo CRUD catégories & tags : non exposé côté UI (lecture seule actuellement) — future page Paramètres
    public function createCategory(string $name, string $savePath = ''): bool
    {
        return $this->postAction('/api/v2/torrents/createCategory', [
            'category' => $name,
            'savePath' => $savePath,
        ]);
    }

    public function deleteCategories(array $categories): bool
    {
        return $this->postAction('/api/v2/torrents/removeCategories', [
            'categories' => implode("\n", $categories),
        ]);
    }

    public function createTags(array $tags): bool
    {
        return $this->postAction('/api/v2/torrents/createTags', ['tags' => implode(',', $tags)]);
    }

    public function deleteTags(array $tags): bool
    {
        return $this->postAction('/api/v2/torrents/deleteTags', ['tags' => implode(',', $tags)]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Transfert global
    // ══════════════════════════════════════════════════════════════════════════

    public function getTransferInfo(): array
    {
        $sid  = $this->login();
        $data = $this->get('/api/v2/transfer/info', [], $sid);
        if ($data === null) return [];

        return [
            'dl_info_speed'    => $data['dl_info_speed'] ?? 0,
            'up_info_speed'    => $data['up_info_speed'] ?? 0,
            'dl_info_data'     => $data['dl_info_data'] ?? 0,
            'up_info_data'     => $data['up_info_data'] ?? 0,
            'connection_status'=> $data['connection_status'] ?? 'unknown',
            'dht_nodes'        => $data['dht_nodes'] ?? 0,
        ];
    }

    /**
     * Récupère le server_state via /sync/maindata — expose alltime_dl/ul, global_ratio, free_space.
     * Cache 10s (endpoint coûteux, valeurs changent lentement).
     */
    public function getServerState(): array
    {
        $now = microtime(true);
        if ($this->serverStateCache !== null && ($now - $this->serverStateCacheAt) < self::SERVER_STATE_TTL) {
            return $this->serverStateCache;
        }

        $sid  = $this->login();
        $data = $this->get('/api/v2/sync/maindata', ['rid' => 0], $sid);
        if ($data === null || !isset($data['server_state'])) return $this->serverStateCache ?? [];

        $s = $data['server_state'];
        $this->serverStateCache   = [
            'alltime_dl'        => (int)($s['alltime_dl'] ?? 0),
            'alltime_ul'        => (int)($s['alltime_ul'] ?? 0),
            'dl_info_data'      => (int)($s['dl_info_data'] ?? 0),
            'up_info_data'      => (int)($s['up_info_data'] ?? 0),
            'global_ratio'      => (float)($s['global_ratio'] ?? 0),
            'free_space_on_disk'=> (int)($s['free_space_on_disk'] ?? 0),
        ];
        $this->serverStateCacheAt = $now;
        return $this->serverStateCache;
    }

    // @todo Limites de vitesse globales + mode alternatif : setGlobalLimit déjà exposé (api_global_limit) mais getters/toggle non branchés — future page Paramètres
    public function getGlobalDownloadLimit(): int
    {
        $sid = $this->login();
        $body = $this->getRaw('/api/v2/transfer/downloadLimit', [], $sid);
        return (int)($body ?? 0);
    }

    public function getGlobalUploadLimit(): int
    {
        $sid = $this->login();
        $body = $this->getRaw('/api/v2/transfer/uploadLimit', [], $sid);
        return (int)($body ?? 0);
    }

    public function setGlobalDownloadLimit(int $limit): bool
    {
        return $this->postAction('/api/v2/transfer/setDownloadLimit', ['limit' => (string)$limit]);
    }

    public function setGlobalUploadLimit(int $limit): bool
    {
        return $this->postAction('/api/v2/transfer/setUploadLimit', ['limit' => (string)$limit]);
    }

    public function toggleSpeedLimitsMode(): bool
    {
        return $this->postAction('/api/v2/transfer/toggleSpeedLimitsMode', []);
    }

    public function getSpeedLimitsMode(): bool
    {
        $sid = $this->login();
        $body = $this->getRaw('/api/v2/transfer/speedLimitsMode', [], $sid);
        return $body === '1';
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Application
    // ══════════════════════════════════════════════════════════════════════════

    // @todo getVersion : non exposé côté UI — utile pour future page Paramètres (version qBit affichée)
    public function getVersion(): ?string
    {
        $sid = $this->login();
        return $this->getRaw('/api/v2/app/version', [], $sid);
    }

    public function getPreferences(): ?array
    {
        $sid = $this->login();
        return $this->get('/api/v2/app/preferences', [], $sid);
    }

    /** Port BitTorrent sur lequel qBit écoute (à synchroniser avec le port forwarded Gluetun). */
    public function getListenPort(): ?int
    {
        $prefs = $this->getPreferences();
        return isset($prefs['listen_port']) ? (int)$prefs['listen_port'] : null;
    }

    // @todo Dossier de téléchargement par défaut — future page Paramètres
    public function getDefaultSavePath(): ?string
    {
        $sid = $this->login();
        return $this->getRaw('/api/v2/app/defaultSavePath', [], $sid);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Statistiques agrégées
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * @param array|null $torrents Si fourni, évite un re-fetch de /torrents/info.
     */
    public function getStats(?array $torrents = null): array
    {
        $torrents = $torrents ?? $this->getTorrents();
        $transfer = $this->getTransferInfo();
        $server   = $this->getServerState();

        $total       = count($torrents);
        $downloading = count(array_filter($torrents, fn($t) => $t['state'] === 'downloading'));
        $seeding     = count(array_filter($torrents, fn($t) => $t['state'] === 'seeding'));
        $paused      = count(array_filter($torrents, fn($t) => $t['state'] === 'paused'));
        $completed   = count(array_filter($torrents, fn($t) => $t['state'] === 'completed'));
        $errored     = count(array_filter($torrents, fn($t) => $t['state'] === 'error'));
        $stalled     = count(array_filter($torrents, fn($t) => $t['state'] === 'stalled'));

        return [
            'total'       => $total,
            'downloading' => $downloading,
            'seeding'     => $seeding,
            'paused'      => $paused,
            'completed'   => $completed,
            'errored'     => $errored,
            'stalled'     => $stalled,
            'dl_speed'    => $transfer['dl_info_speed'] ?? 0,
            'up_speed'    => $transfer['up_info_speed'] ?? 0,
            'connection'  => $transfer['connection_status'] ?? 'unknown',
            'dht_nodes'   => $transfer['dht_nodes'] ?? 0,
            'dl_session'  => $transfer['dl_info_data'] ?? 0,
            'up_session'  => $transfer['up_info_data'] ?? 0,
            'dl_alltime'  => $server['alltime_dl'] ?? 0,
            'up_alltime'  => $server['alltime_ul'] ?? 0,
            'global_ratio'=> $server['global_ratio'] ?? 0,
            'free_space'  => $server['free_space_on_disk'] ?? 0,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Normalisation
    // ══════════════════════════════════════════════════════════════════════════

    private function normalizeTorrent(array $t): array
    {
        return [
            'hash'         => $t['hash'] ?? '',
            'name'         => $t['name'] ?? '—',
            'size'         => $t['size'] ?? 0,
            'total_size'   => $t['total_size'] ?? $t['size'] ?? 0,
            'downloaded'   => $t['downloaded'] ?? 0,
            'uploaded'     => $t['uploaded'] ?? 0,
            'progress'     => round(($t['progress'] ?? 0) * 100, 1),
            'dlspeed'      => $t['dlspeed'] ?? 0,
            'upspeed'      => $t['upspeed'] ?? 0,
            'eta'          => $t['eta'] ?? 8640000,
            'state'        => $this->normalizeState($t['state'] ?? ''),
            'raw_state'    => $t['state'] ?? '',
            'category'     => $t['category'] ?? '',
            'tags'         => $t['tags'] ?? '',
            'ratio'        => round($t['ratio'] ?? 0, 2),
            'num_seeds'    => $t['num_seeds'] ?? 0,
            'num_leechs'   => $t['num_leechs'] ?? 0,
            'num_complete'  => $t['num_complete'] ?? 0,
            'num_incomplete'=> $t['num_incomplete'] ?? 0,
            'added_on'     => $t['added_on'] ?? null,
            'completion_on' => $t['completion_on'] ?? null,
            'save_path'    => $t['save_path'] ?? '',
            'content_path' => $t['content_path'] ?? '',
            'tracker'      => $t['tracker'] ?? '',
            'dl_limit'     => $t['dl_limit'] ?? -1,
            'up_limit'     => $t['up_limit'] ?? -1,
            'seq_dl'       => (bool)($t['seq_dl'] ?? false),
            'f_l_piece_prio' => (bool)($t['f_l_piece_prio'] ?? false),
            'force_start'  => (bool)($t['force_start'] ?? false),
            'super_seeding' => (bool)($t['super_seeding'] ?? false),
            'auto_tmm'     => (bool)($t['auto_tmm'] ?? false),
            'priority'     => $t['priority'] ?? 0,
            'availability' => round($t['availability'] ?? 0, 3),
        ];
    }

    private function normalizeState(string $state): string
    {
        return match(true) {
            in_array($state, ['downloading', 'metaDL', 'checkingDL', 'forcedDL', 'forcedMetaDL', 'allocating']) => 'downloading',
            in_array($state, ['uploading', 'forcedUP', 'stalledUP'])       => 'seeding',
            // qBit v5.x : stoppedDL/stoppedUP (renommés depuis pausedDL/pausedUP)
            in_array($state, ['pausedDL', 'pausedUP', 'stoppedDL', 'stoppedUP']) => 'paused',
            in_array($state, ['queuedDL', 'queuedUP'])                     => 'queued',
            in_array($state, ['checkingUP', 'checkingResumeData'])         => 'checking',
            $state === 'stalledDL'                                         => 'stalled',
            $state === 'error' || $state === 'missingFiles'                => 'error',
            $state === 'moving'                                            => 'moving',
            $state === ''                                                  => 'completed', // string vide = torrent complété sans précision qBit
            default                                                        => 'unknown',
        };
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Authentification
    // ══════════════════════════════════════════════════════════════════════════

    private function login(): ?string
    {
        // Réutilise le SID déjà obtenu (survit entre requêtes dans le même PHP-FPM worker).
        if ($this->sid !== null) return $this->sid;

        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . '/api/v2/auth/login';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['username' => $this->user, 'password' => $this->password]),
            CURLOPT_HEADER         => true,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $this->logger->warning('QBittorrentClient login error', ['error' => curl_error($ch)]);
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        preg_match('/Set-Cookie:\s*SID=([^;]+)/i', $response, $m);
        $this->sid = $m[1] ?? null;
        return $this->sid;
    }

    /** Invalide la session (à appeler si qBit rejette un appel : SID expiré). */
    private function invalidateSession(): void
    {
        $this->sid = null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  HTTP
    // ══════════════════════════════════════════════════════════════════════════

    private function get(string $path, array $params = [], ?string $sid = null): ?array
    {
        $body = $this->getRaw($path, $params, $sid);
        if ($body === null) return null;
        return json_decode($body, true) ?? null;
    }

    private function getRaw(string $path, array $params = [], ?string $sid = null): ?string
    {
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($params) $url .= '?' . http_build_query($params);

        $headers = [];
        if ($sid) $headers[] = 'Cookie: SID=' . $sid;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false) {
            $this->logger->warning('QBittorrentClient GET error', ['path' => $path, 'error' => curl_error($ch)]);
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        if ($code !== 200) {
            // SID expiré → on invalide, un prochain appel re-login automatiquement
            if ($code === 403 || $code === 401) {
                $this->invalidateSession();
            }
            $this->logger->warning('QBittorrentClient GET error', ['path' => $path, 'code' => $code]);
            return null;
        }

        return $body;
    }

    private function postAction(string $path, array $params = [], bool $retried = false): bool
    {
        $sid = $this->login();
        if (!$sid) return false;

        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;
        $headers = ['Cookie: SID=' . $sid];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false) {
            $this->logger->warning('QBittorrentClient POST error', ['path' => $path, 'error' => curl_error($ch)]);
            curl_close($ch);
            return false;
        }
        curl_close($ch);

        if ($code !== 200) {
            // SID expiré → invalide + 1 retry après re-login auto
            if (($code === 401 || $code === 403) && !$retried) {
                $this->invalidateSession();
                return $this->postAction($path, $params, true);
            }
            $this->logger->warning('QBittorrentClient POST error', ['path' => $path, 'code' => $code]);
            return false;
        }

        return true;
    }
}
