<?php

namespace App\Service\Media;

/**
 * Résout un nom de release torrent (ex: "Dune.Part.Two.2024.2160p.BluRay")
 * vers un film Radarr ou une série Sonarr déjà en bibliothèque.
 * Logique extraite de QBittorrentController pour testabilité + réutilisation.
 */
class TorrentResolverService
{
    /** Seuil minimum du score de matching pour considérer un résultat valide. */
    public const MIN_SCORE = 70;

    public function __construct(
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
    ) {}

    /**
     * @return array{found: bool, id?: int, title?: string, url?: string, error?: string, parsed?: array}
     */
    public function resolve(string $pipeline, string $torrentName): array
    {
        $parsed = self::parseReleaseName($torrentName);
        $needle = self::normalizeTitle($parsed['title']);
        if ($needle === '') {
            return ['found' => false, 'error' => 'Titre non parsable', 'parsed' => $parsed];
        }

        if ($pipeline === 'radarr') {
            return $this->resolveMovie($needle, $parsed);
        }
        if ($pipeline === 'sonarr') {
            return $this->resolveSeries($needle, $parsed);
        }
        return ['found' => false, 'error' => 'Pipeline inconnu'];
    }

    private function resolveMovie(string $needle, array $parsed): array
    {
        $best = null;
        $bestScore = 0;
        foreach ($this->radarr->getRawMovies() as $m) {
            $score = $this->scoreMatch($m['title'] ?? '', $needle, $m['year'] ?? null, $parsed['year']);
            if ($score > $bestScore) { $bestScore = $score; $best = $m; }
        }
        if ($best && $bestScore >= self::MIN_SCORE) {
            return [
                'found' => true,
                'id'    => (int)$best['id'],
                'title' => (string)($best['title'] ?? ''),
                'url'   => '/medias/films?open=' . (int)$best['id'],
            ];
        }
        return ['found' => false, 'error' => 'Aucun match dans la bibliothèque', 'parsed' => $parsed];
    }

    private function resolveSeries(string $needle, array $parsed): array
    {
        $best = null;
        $bestScore = 0;
        foreach ($this->sonarr->getRawAllSeries() as $s) {
            $score = $this->scoreMatch($s['title'] ?? '', $needle, $s['year'] ?? null, $parsed['year']);
            if ($score > $bestScore) { $bestScore = $score; $best = $s; }
        }
        if ($best && $bestScore >= self::MIN_SCORE) {
            return [
                'found' => true,
                'id'    => (int)$best['id'],
                'title' => (string)($best['title'] ?? ''),
                'url'   => '/medias/series?open=' . (int)$best['id'],
            ];
        }
        return ['found' => false, 'error' => 'Aucun match dans la bibliothèque', 'parsed' => $parsed];
    }

    /** Longueur minimale de needle pour accepter un match `contains` (évite "It" qui matche "Split"). */
    private const MIN_CONTAINS_LEN = 4;

    /**
     * Score de matching entre un titre bibliothèque et un titre parsé.
     * 100 = exact, 70 = contains (si needle >= 4 chars), +20 si même année, 0 sinon.
     */
    private function scoreMatch(string $libTitle, string $needle, ?int $libYear, ?int $parsedYear): int
    {
        $titleNorm = self::normalizeTitle($libTitle);
        if ($titleNorm === '') return 0;

        $score = 0;
        if ($titleNorm === $needle) {
            $score = 100;
        } elseif (
            mb_strlen($needle) >= self::MIN_CONTAINS_LEN
            && mb_strlen($titleNorm) >= self::MIN_CONTAINS_LEN
            && (str_contains($titleNorm, $needle) || str_contains($needle, $titleNorm))
        ) {
            $score = 70;
        }
        if ($score > 0 && $parsedYear !== null && (int)$libYear === $parsedYear) {
            $score += 20;
        }
        return $score;
    }

    /**
     * Extrait titre + année d'un nom de release torrent.
     * @return array{title: string, year: int|null}
     */
    public static function parseReleaseName(string $raw): array
    {
        // Remplace les séparateurs par des espaces
        $clean = preg_replace('/[\._]+/', ' ', $raw);
        $year  = null;

        // Cherche la DERNIÈRE année qui précède un marker de release (1080p, BluRay, S01E01, etc.)
        // — évite de couper à une année qui fait partie du titre (ex: "1917 2019 1080p" → année=2019, pas 1917).
        if (preg_match('/\b(19\d{2}|20\d{2})\b(?=[^0-9]*(?:\b(?:2160p|1080p|720p|480p|BluRay|WEBRip|WEB-DL|HDRip|DVDRip|BDRip|REMUX|DV|HDR|x264|x265|H\.?264|H\.?265|HEVC|S\d{2}E?\d*|COMPLETE)\b|$))/i', $clean, $m, PREG_OFFSET_CAPTURE)) {
            $year  = (int)$m[1][0];
            $clean = trim(substr($clean, 0, $m[1][1]));
        } else {
            // Pas d'année détectable : coupe au premier token quality/source
            $clean = preg_split('/\b(2160p|1080p|720p|480p|BluRay|WEBRip|WEB-DL|HDRip|DVDRip|BDRip|S\d{2}E\d{2})\b/i', $clean)[0] ?? $clean;
        }
        $clean = trim(preg_replace('/\s+/', ' ', (string)$clean));
        return ['title' => $clean, 'year' => $year];
    }

    public static function normalizeTitle(string $s): string
    {
        $s = mb_strtolower($s);
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }
}
