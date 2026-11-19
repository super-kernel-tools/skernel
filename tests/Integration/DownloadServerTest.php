<?php

declare(strict_types=1);

namespace Src\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Src\Filesystem\Directory;
use Src\Toolchain\Download;
use Src\Toolchain\Exception\DownloadNotFoundException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Process\Process;

use function count;
use function fclose;
use function file;
use function file_put_contents;
use function fsockopen;
use function getenv;
use function glob;
use function hash;
use function hrtime;
use function is_string;
use function mkdir;
use function putenv;
use function stream_socket_get_name;
use function stream_socket_server;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

use const FILE_IGNORE_NEW_LINES;
use const PHP_BINARY;

/**
 * Downloads from a local PHP server that answers each path with a failure SKERNEL must tell apart.
 */
final class DownloadServerTest extends TestCase
{
    private const array VARIABLES = [
        'http_proxy',
        'HTTP_PROXY',
        'no_proxy',
        'NO_PROXY',
    ];

    private const string ROUTER = <<<'PHP'
        <?php
        file_put_contents(__DIR__ . '/requests', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);

        switch ($_SERVER['REQUEST_URI']) {
            case '/recovering':
                $first = !is_file(__DIR__ . '/recovered');
                touch(__DIR__ . '/recovered');
                http_response_code($first ? 503 : 200);
                echo $first ? 'unavailable' : 'digest';
                break;
            case '/missing':
                http_response_code(404);
                break;
            case '/forbidden':
                http_response_code(403);
                break;
            case '/interrupted':
                header('Content-Length: 1000000');
                echo 'runtime';
                break;
            default:
                echo 'runtime';
        }
        PHP;

    private string $directory;

    private string $address;

    private Process $server;

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'skernel-server-test-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory, 0o700));
        $this->directory = $directory;
        self::assertNotFalse(file_put_contents($directory . '/router.php', self::ROUTER));
        $this->address = $this->freeAddress();
        $this->server = new Process([
            PHP_BINARY,
            '-S',
            $this->address,
            $directory . '/router.php',
        ], $directory);
        $this->server->start();

        for ($attempt = 0; 100 > $attempt && false === @fsockopen('tcp://' . $this->address); ++$attempt) {
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
        Directory::remove($this->directory);
    }

    public function testRetriesAServerErrorUntilTheServerAnswers(): void
    {
        self::assertSame('digest', Download::contents('http://' . $this->address . '/recovering'));
        self::assertSame([
            '/recovering',
            '/recovering',
        ], $this->requests());
    }

    public function testReportsAMissingFileWithoutRetrying(): void
    {
        try {
            Download::contents('http://' . $this->address . '/missing');
            self::fail('A missing file must be reported as not found.');
        } catch (DownloadNotFoundException $exception) {
            self::assertSame('Unable to download http://' . $this->address . '/missing.', $exception->getMessage());
        }

        self::assertSame([
            '/missing',
        ], $this->requests());
    }

    public function testDoesNotRetryAnotherClientError(): void
    {
        try {
            Download::contents('http://' . $this->address . '/forbidden');
            self::fail('A refused request must fail.');
        } catch (RuntimeException $exception) {
            self::assertNotInstanceOf(DownloadNotFoundException::class, $exception);
            self::assertSame('Unable to download http://' . $this->address . '/forbidden.', $exception->getMessage());
        }

        self::assertSame([
            '/forbidden',
        ], $this->requests());
    }

    public function testGivesUpAfterThreeAttemptsWithoutAResponse(): void
    {
        $address = $this->freeAddress();
        $started = hrtime(true);

        try {
            Download::contents('http://' . $address . '/runtime');
            self::fail('A server that never answers must fail the download.');
        } catch (RuntimeException $exception) {
            self::assertNotInstanceOf(DownloadNotFoundException::class, $exception);
            self::assertSame('Unable to download http://' . $address . '/runtime.', $exception->getMessage());
        }

        self::assertGreaterThanOrEqual(3.0, (hrtime(true) - $started) / 1e9);
    }

    public function testPublishesAFileWhoseDownloadIsComplete(): void
    {
        Download::file(
            'http://' . $this->address . '/runtime',
            $this->directory . '/target',
            hash('sha256', 'runtime'),
        );

        self::assertStringEqualsFile($this->directory . '/target', 'runtime');
    }

    public function testReportsAnInterruptedDownloadInsteadOfItsChecksum(): void
    {
        try {
            Download::file(
                'http://' . $this->address . '/interrupted',
                $this->directory . '/target',
                hash('sha256', 'runtime'),
            );
            self::fail('A download shorter than its announced length must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'The download of http://' . $this->address . '/interrupted was interrupted after 0.0 MB of 1.0 MB.',
                $exception->getMessage(),
            );
        }

        self::assertSame([], glob($this->directory . '/.download-*'));
        self::assertFileDoesNotExist($this->directory . '/target');
    }

    /** @return list<string> The paths the server received, in order */
    private function requests(): array
    {
        $requests = file($this->directory . '/requests', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($requests);
        self::assertGreaterThan(0, count($requests));

        return $requests;
    }

    private function freeAddress(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return $address;
    }
}
