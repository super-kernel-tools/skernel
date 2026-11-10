<?php

declare(strict_types=1);

namespace Src\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Src\Filesystem\Directory;
use Src\Toolchain\Download;
use Symfony\Component\Process\Process;

use function base64_encode;
use function fclose;
use function file_put_contents;
use function fsockopen;
use function getenv;
use function is_string;
use function mkdir;
use function putenv;
use function stream_socket_get_name;
use function stream_socket_server;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

use const PHP_BINARY;

/**
 * Downloads through a local PHP server that stands in for an HTTP proxy and echoes the request it receives.
 */
final class DownloadProxyTest extends TestCase
{
    private const array VARIABLES = [
        'http_proxy',
        'HTTP_PROXY',
        'no_proxy',
        'NO_PROXY',
    ];

    private string $directory;

    private Process $proxy;

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'skernel-proxy-test-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory, 0o700));
        $this->directory = $directory;
        self::assertNotFalse(file_put_contents(
            $directory . '/proxy.php',
            "<?php\necho \$_SERVER['REQUEST_URI'], ' ', \$_SERVER['HTTP_PROXY_AUTHORIZATION'] ?? '-';\n",
        ));
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $this->proxy = new Process([
            PHP_BINARY,
            '-S',
            $address,
            $directory . '/proxy.php',
        ], $directory);
        $this->proxy->start();

        for ($attempt = 0; 100 > $attempt && false === @fsockopen('tcp://' . $address); ++$attempt) {
            usleep(20_000);
        }

        foreach (self::VARIABLES as $variable) {
            $this->previous[$variable] = getenv($variable);
            putenv($variable);
        }

        putenv('http_proxy=user:secret@' . $address);
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $variable => $value) {
            putenv(is_string($value) ? $variable . '=' . $value : $variable);
        }

        $this->proxy->stop();
        Directory::remove($this->directory);
    }

    public function testRequestsTheFullUriWithTheProxyCredentials(): void
    {
        self::assertSame(
            'http://skernel.invalid/release?version=1 Basic ' . base64_encode('user:secret'),
            Download::contents('http://skernel.invalid/release?version=1'),
        );
    }
}
