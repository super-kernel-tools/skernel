<?php

declare(strict_types=1);

namespace Src\Tests\Integration;

use Src\Tests\Unit\Toolchain\ToolchainTestCase;
use Src\Toolchain\DailyCache;
use Src\Toolchain\Home;
use Src\Toolchain\ZigMirrors;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Process\Process;

use function array_map;
use function fclose;
use function file;
use function file_get_contents;
use function file_put_contents;
use function fsockopen;
use function getenv;
use function hash;
use function is_string;
use function json_encode;
use function mkdir;
use function parse_url;
use function putenv;
use function sort;
use function stream_socket_get_name;
use function stream_socket_server;
use function time;
use function usleep;

use const FILE_IGNORE_NEW_LINES;
use const PHP_BINARY;
use const PHP_URL_PORT;

/**
 * Downloads Zig from a local PHP server that stands in for the community mirrors: one does not have the archive, one
 * serves an altered archive, and one serves the verified archive.
 */
final class ZigMirrorsTest extends ToolchainTestCase
{
    private const array VARIABLES = [
        'http_proxy',
        'HTTP_PROXY',
        'https_proxy',
        'HTTPS_PROXY',
        'no_proxy',
        'NO_PROXY',
    ];

    private const string ASSET = 'zig-test.tar.xz';

    private const string ARCHIVE = 'verified archive';

    private int $port;

    private Process $server;

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertNotFalse(file_put_contents(
            $this->home . '/mirror.php',
            "<?php\n"
            . "file_put_contents(__DIR__ . '/requests.log', \$_SERVER['REQUEST_URI'] . \"\\n\", FILE_APPEND);\n"
            . "match (parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH)) {\n"
            . "    '/verified/" . self::ASSET . "' => print '" . self::ARCHIVE . "',\n"
            . "    '/altered/" . self::ASSET . "' => print 'altered archive',\n"
            . "    default => http_response_code(404),\n"
            . "};\n",
        ));
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $this->port = (int) parse_url('tcp://' . stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);
        $this->server = new Process([
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $this->port,
            $this->home . '/mirror.php',
        ], $this->home);
        $this->server->start();

        for ($attempt = 0; 100 > $attempt && false === @fsockopen('tcp://127.0.0.1:' . $this->port); ++$attempt) {
            usleep(20_000);
        }

        foreach (self::VARIABLES as $variable) {
            $this->previous[$variable] = getenv($variable);
            putenv($variable);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $variable => $value) {
            putenv(is_string($value) ? $variable . '=' . $value : $variable);
        }

        $this->server->stop();
        parent::tearDown();
    }

    public function testDownloadsTheVerifiedArchiveFromAMirror(): void
    {
        $this->mirrors(time(), 'missing', 'altered', 'verified');

        $this->download();

        self::assertSame(self::ARCHIVE, file_get_contents($this->home . '/' . self::ASSET));
        self::assertContains('/verified/' . self::ASSET . '?source=skernel', $this->requests());
    }

    public function testUsesTheCachedMirrorsWhenZiglangOrgCannotBeReached(): void
    {
        $this->mirrors(0, 'verified');
        putenv('https_proxy=socks5://proxy.invalid:1080');

        $this->download();

        self::assertSame(self::ARCHIVE, file_get_contents($this->home . '/' . self::ASSET));
        self::assertStringContainsString(
            '"checked":0',
            (string) file_get_contents($this->home . '/cache/zig-mirrors.json'),
        );
    }

    public function testFallsBackToZiglangOrgWhenNoMirrorServesTheArchive(): void
    {
        $this->mirrors(time(), 'missing', 'altered');
        putenv('https_proxy=socks5://proxy.invalid:1080');

        try {
            $this->download();
            self::fail('The download succeeded without a mirror that serves the verified archive.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'The https_proxy environment variable is not a valid proxy URL.',
                $exception->getMessage(),
            );
        }

        self::assertFileDoesNotExist($this->home . '/' . self::ASSET);
        self::assertSame([
            '/altered/' . self::ASSET . '?source=skernel',
            '/missing/' . self::ASSET . '?source=skernel',
        ], $this->requests());
    }

    private function download(): void
    {
        new ZigMirrors(new DailyCache(new Home()))->download(
            '0.17.1',
            self::ASSET,
            $this->home . '/' . self::ASSET,
            hash('sha256', self::ARCHIVE),
        );
    }

    /** Caches a mirror list, checked at the given time, of paths on the local server */
    private function mirrors(int $checked, string ...$paths): void
    {
        $cache = json_encode([
            'community' => [
                'checked' => $checked,
                'data'    => array_map(
                    fn(string $path): string => 'http://127.0.0.1:' . $this->port . '/' . $path,
                    $paths,
                ),
            ],
        ]);
        self::assertIsString($cache);
        self::assertTrue(mkdir($this->home . '/cache', 0o700));
        self::assertNotFalse(file_put_contents($this->home . '/cache/zig-mirrors.json', $cache));
    }

    /** @return list<string> The request URIs the local server received, in sorted order */
    private function requests(): array
    {
        $requests = file($this->home . '/requests.log', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($requests);
        sort($requests);

        return $requests;
    }
}
