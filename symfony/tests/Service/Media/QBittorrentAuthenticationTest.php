<?php

namespace App\Service\Media {
    final class QBittorrentCurlFakeHandle
    {
        /** @param array<string, mixed> $options */
        public function __construct(
            public string $url,
            public array $options = [],
            public int $code = 0,
            public string $error = '',
        ) {}
    }

    final class QBittorrentCurlFake
    {
        public static bool $enabled = false;

        /** @var list<array{code:int, body:string|false, error?:string}> */
        public static array $responses = [];

        /** @var list<QBittorrentCurlFakeHandle> */
        public static array $handles = [];

        /** @param list<array{code:int, body:string|false, error?:string}> $responses */
        public static function enable(array $responses): void
        {
            self::$enabled = true;
            self::$responses = $responses;
            self::$handles = [];
        }

        public static function disable(): void
        {
            self::$enabled = false;
            self::$responses = [];
            self::$handles = [];
        }
    }

    function curl_init(string $url): mixed
    {
        if (!QBittorrentCurlFake::$enabled) {
            return \curl_init($url);
        }

        $handle = new QBittorrentCurlFakeHandle($url);
        QBittorrentCurlFake::$handles[] = $handle;

        return $handle;
    }

    /** @param array<int, mixed> $options */
    function curl_setopt_array(mixed $handle, array $options): bool
    {
        if (!QBittorrentCurlFake::$enabled) {
            return \curl_setopt_array($handle, $options);
        }

        $handle->options = $options;

        return true;
    }

    function curl_exec(mixed $handle): string|bool
    {
        if (!QBittorrentCurlFake::$enabled) {
            return \curl_exec($handle);
        }

        $response = array_shift(QBittorrentCurlFake::$responses) ?? ['code' => 500, 'body' => 'unexpected fake curl call'];
        $handle->code = $response['code'];
        $handle->error = $response['error'] ?? '';

        return $response['body'];
    }

    function curl_getinfo(mixed $handle, ?int $option = null): mixed
    {
        if (!QBittorrentCurlFake::$enabled) {
            return $option === null ? \curl_getinfo($handle) : \curl_getinfo($handle, $option);
        }

        if ($option === \CURLINFO_HTTP_CODE) {
            return $handle->code;
        }

        return null;
    }

    function curl_error(mixed $handle): string
    {
        if (!QBittorrentCurlFake::$enabled) {
            return \curl_error($handle);
        }

        return $handle->error;
    }

    function curl_close(mixed $handle): void
    {
        if (!QBittorrentCurlFake::$enabled) {
            \curl_close($handle);
        }
    }
}

namespace App\Tests\Service\Media {
    use App\Service\ConfigService;
    use App\Service\Media\QBittorrentClient;
    use App\Service\Media\QBittorrentCurlFake;
    use App\Service\Media\ServiceHealthCache;
    use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\LoggerInterface;
    use Symfony\Component\Cache\Adapter\ArrayAdapter;

    #[AllowMockObjectsWithoutExpectations]
    final class QBittorrentAuthenticationTest extends TestCase
    {
        protected function tearDown(): void
        {
            QBittorrentCurlFake::disable();
        }

        private function makeClient(): QBittorrentClient
        {
            $config = $this->createMock(ConfigService::class);
            $config->method('require')->willReturn('http://qbit.lan:8081');
            $config->method('get')->willReturnCallback(fn (string $key) => match ($key) {
                'qbittorrent_user' => 'admin',
                'qbittorrent_password' => 'secret',
                default => null,
            });

            return new QBittorrentClient(
                $config,
                $this->createMock(LoggerInterface::class),
                new ServiceHealthCache(new ArrayAdapter()),
            );
        }

        public function testLoginAcceptsHttp200AndSidCookie(): void
        {
            QBittorrentCurlFake::enable([
                ['code' => 200, 'body' => "HTTP/1.1 200 OK\r\nSet-Cookie: SID=abc123; HttpOnly; path=/\r\n\r\nOk."],
            ]);

            $client = $this->makeClient();
            $login = new \ReflectionMethod($client, 'login');
            $login->setAccessible(true);

            $this->assertSame('SID=abc123', $login->invoke($client));
        }

        public function testLoginAcceptsHttp204AndQbtSidCookie(): void
        {
            QBittorrentCurlFake::enable([
                ['code' => 204, 'body' => "HTTP/1.1 204 No Content\r\nSet-Cookie: QBT_SID_8081=def456; HttpOnly; SameSite=Strict; expires=Fri, 08 May 2026 00:00:00 GMT; path=/\r\n\r\n"],
                ['code' => 200, 'body' => 'v5.2.0'],
            ]);

            $client = $this->makeClient();

            $this->assertSame('v5.2.0', $client->getVersion());
            $this->assertSame(['Cookie: QBT_SID_8081=def456'], QBittorrentCurlFake::$handles[1]->options[\CURLOPT_HTTPHEADER]);
        }

        public function testHttp403TriggersExactlyOneReloginAndRetry(): void
        {
            QBittorrentCurlFake::enable([
                ['code' => 200, 'body' => "HTTP/1.1 200 OK\r\nSet-Cookie: QBT_SID_8081=first; path=/\r\n\r\nOk."],
                ['code' => 403, 'body' => 'Forbidden'],
                ['code' => 204, 'body' => "HTTP/1.1 204 No Content\r\nSet-Cookie: QBT_SID_8081=second; path=/\r\n\r\n"],
                ['code' => 200, 'body' => 'v5.2.1'],
            ]);

            $client = $this->makeClient();

            $this->assertSame('v5.2.1', $client->getVersion());

            $loginCalls = array_values(array_filter(
                QBittorrentCurlFake::$handles,
                fn ($handle) => str_ends_with($handle->url, '/api/v2/auth/login'),
            ));
            $this->assertCount(2, $loginCalls);
            $this->assertCount(4, QBittorrentCurlFake::$handles);
            $this->assertSame(['Cookie: QBT_SID_8081=first'], QBittorrentCurlFake::$handles[1]->options[\CURLOPT_HTTPHEADER]);
            $this->assertSame(['Cookie: QBT_SID_8081=second'], QBittorrentCurlFake::$handles[3]->options[\CURLOPT_HTTPHEADER]);
        }
    }
}
