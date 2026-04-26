<?php

namespace App\Tests\Service;

use App\Service\AppVersion;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AppVersionTest extends TestCase
{
    public function testCurrentReturnsConstant(): void
    {
        $svc = new AppVersion($this->emptyCache(), new NullLogger());
        $this->assertSame(AppVersion::VERSION, $svc->current());
    }

    public function testReleasesReadsFromCacheWhenHit(): void
    {
        $cached = [
            ['tag' => '1.2.3', 'name' => 'v1.2.3', 'body' => 'changes', 'published_at' => '2026-04-26T10:00:00Z', 'html_url' => 'https://example/1.2.3'],
        ];

        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($cached);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        $svc = new AppVersion($pool, new NullLogger());
        $this->assertSame($cached, $svc->releases());
    }

    public function testLatestReturnsFirstTagFromCache(): void
    {
        $cached = [
            ['tag' => '9.9.9', 'name' => 'v9.9.9', 'body' => '', 'published_at' => '', 'html_url' => ''],
            ['tag' => '0.0.1', 'name' => 'v0.0.1', 'body' => '', 'published_at' => '', 'html_url' => ''],
        ];
        $svc = $this->withCachedReleases($cached);
        $this->assertSame('9.9.9', $svc->latest());
    }

    public function testIsUpdateAvailableTrueWhenLatestHigher(): void
    {
        // Build a tag strictly higher than current — works regardless of constant value.
        $current = AppVersion::VERSION;
        $parts = array_map('intval', explode('.', $current));
        $parts[0]++;
        $higher = implode('.', $parts);

        $svc = $this->withCachedReleases([
            ['tag' => $higher, 'name' => '', 'body' => '', 'published_at' => '', 'html_url' => ''],
        ]);
        $this->assertTrue($svc->isUpdateAvailable());
    }

    public function testIsUpdateAvailableFalseWhenSame(): void
    {
        $svc = $this->withCachedReleases([
            ['tag' => AppVersion::VERSION, 'name' => '', 'body' => '', 'published_at' => '', 'html_url' => ''],
        ]);
        $this->assertFalse($svc->isUpdateAvailable());
    }

    public function testIsUpdateAvailableFalseWhenNoLatest(): void
    {
        $svc = $this->withCachedReleases([]);
        $this->assertFalse($svc->isUpdateAvailable());
    }

    public function testResetClearsInProcessCache(): void
    {
        $svc = $this->withCachedReleases([
            ['tag' => '1.0.0', 'name' => '', 'body' => '', 'published_at' => '', 'html_url' => ''],
        ]);
        $this->assertSame('1.0.0', $svc->latest());
        $svc->reset();
        // Should not throw — pool still returns the same cached payload.
        $this->assertSame('1.0.0', $svc->latest());
    }

    /**
     * @param list<array{tag:string,name:string,body:string,published_at:string,html_url:string}> $cached
     */
    private function withCachedReleases(array $cached): AppVersion
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($cached);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        return new AppVersion($pool, new NullLogger());
    }

    private function emptyCache(): CacheItemPoolInterface
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        return $pool;
    }
}
