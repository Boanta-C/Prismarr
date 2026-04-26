<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Version + release-notes provider for the /admin/settings → Updates page.
 *
 * The current version is a constant bumped by hand on each release.
 * Release notes are fetched from the GitHub Releases API (public, no auth)
 * and cached for an hour. If the network is unavailable, the page falls
 * back to displaying just the current version.
 */
class AppVersion implements ResetInterface
{
    /** Bumped on every release. Source of truth for the running build. */
    public const VERSION = '1.0.4';

    private const GITHUB_API_URL = 'https://api.github.com/repos/Shoshuo/Prismarr/releases?per_page=15';
    private const CACHE_KEY      = 'app_version.releases';
    private const CACHE_TTL      = 3600; // 1 hour

    /** @var array<int, array{tag:string,name:string,body:string,published_at:string,html_url:string}>|null */
    private ?array $releasesInProcess = null;

    public function __construct(
        private readonly CacheItemPoolInterface $cacheApp,
        private readonly LoggerInterface        $logger,
    ) {}

    public function reset(): void
    {
        $this->releasesInProcess = null;
    }

    public function current(): string
    {
        return self::VERSION;
    }

    /**
     * Latest GitHub release tag (without the leading `v`), or null if the
     * API is unreachable or returned nothing usable.
     */
    public function latest(): ?string
    {
        $first = $this->releases()[0] ?? null;
        return $first['tag'] ?? null;
    }

    /**
     * @return bool true if a strictly newer version is available on GitHub.
     */
    public function isUpdateAvailable(): bool
    {
        $latest = $this->latest();
        if ($latest === null) {
            return false;
        }
        return version_compare($latest, self::VERSION, '>');
    }

    /**
     * @return array<int, array{tag:string,name:string,body:string,published_at:string,html_url:string}>
     */
    public function releases(): array
    {
        if ($this->releasesInProcess !== null) {
            return $this->releasesInProcess;
        }

        $item = $this->cacheApp->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_array($cached)) {
                return $this->releasesInProcess = $cached;
            }
        }

        $fetched = $this->fetchFromGithub();
        if ($fetched === null) {
            // Don't poison the cache with a failure — let the next request
            // try again (network may be intermittent). Return empty list.
            return $this->releasesInProcess = [];
        }

        $item->set($fetched);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cacheApp->save($item);

        return $this->releasesInProcess = $fetched;
    }

    /**
     * @return array<int, array{tag:string,name:string,body:string,published_at:string,html_url:string}>|null
     */
    private function fetchFromGithub(): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::GITHUB_API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github+json',
                'User-Agent: Prismarr/' . self::VERSION,
            ],
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '' || $code !== 200) {
            $this->logger->info('AppVersion GitHub releases fetch failed', [
                'code'  => $code,
                'error' => $err,
            ]);
            return null;
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return null;
        }

        $releases = [];
        foreach ($data as $r) {
            if (!is_array($r)) {
                continue;
            }
            $tag = (string) ($r['tag_name'] ?? '');
            // Strip leading "v" for cleaner display + version_compare.
            $tag = ltrim($tag, 'vV');
            if ($tag === '') {
                continue;
            }
            $releases[] = [
                'tag'          => $tag,
                'name'         => (string) ($r['name'] ?? $tag),
                'body'         => (string) ($r['body'] ?? ''),
                'published_at' => (string) ($r['published_at'] ?? ''),
                'html_url'     => (string) ($r['html_url'] ?? ''),
            ];
        }

        return $releases;
    }
}
