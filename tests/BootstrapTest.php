<?php

declare(strict_types=1);

namespace Src\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use Src\Filesystem\Directory;
use Src\Project\ProjectLocator;
use Src\Toolchain\DailyCache;
use Src\Toolchain\Extensions;
use Src\Toolchain\ExtensionVersions;
use Src\Toolchain\Home;
use Src\Toolchain\RuntimeProvider;
use Src\Toolchain\StaticPhpCli;
use Src\Toolchain\ZigMirrors;

use function array_filter;
use function dirname;
use function end;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function implode;
use function json_decode;
use function mkdir;
use function preg_replace;
use function putenv;
use function symlink;
use function sys_get_temp_dir;
use function tempnam;
use function touch;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;
use const PHP_RELEASE_VERSION;

final class BootstrapTest extends TestCase
{
    private const string LOGO = <<<'LOGO'
           _____ __ __ __________  _   __________
          / ___// //_// ____/ __ \/ | / / ____/ /
          \__ \/ ,<  / __/ / /_/ /  |/ / __/ / /
         ___/ / /| |/ /___/ _, _/ /|  / /___/ /___
        /____/_/ |_/_____/_/ |_/_/ |_/_____/_____/
        LOGO;

    /** @throws JsonException When the root manifest is not valid JSON */
    public function testVersionReportsSKERNELWithTheRootPackageVersion(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $expected = isset($manifest['version']) ? 'SKERNEL ' . $manifest['version'] : 'SKERNEL';

        $output = $this->bootstrap('--version', $status);
        $lines = array_filter($output);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame($expected, end($lines));
    }

    public function testOverviewOpensWithTheSKERNELLogo(): void
    {
        $output = $this->bootstrap('--no-ansi', $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString(self::LOGO . "\nSKERNEL", implode("\n", $output));
    }

    /** @throws JsonException When standard output is not exactly the JSON listing */
    public function testStandardOutputCarriesOnlyTheCommandOutput(): void
    {
        $output = $this->bootstrap('list --format=json', $status, '2>/dev/null');
        $listing = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $status);
        self::assertSame('SKERNEL', $listing['application']['name']);
    }

    public function testScanDiagnosticsOfSKERNELItselfAreNotWritten(): void
    {
        $output = $this->bootstrap('--version', $status, '2>&1 >/dev/null');

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([], $output);
    }

    public function testLogRecordsBelowWarningAreNotWritten(): void
    {
        $output = $this->bootstrap('list --format=json', $status, '2>&1 >/dev/null');

        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringNotContainsString('[INFO]', implode("\n", $output));
    }

    public function testBuildWritesTheBuildArtifactsOfTheProjectWithItsManagedPhp(): void
    {
        $root = dirname(__DIR__);
        $home = tempnam(sys_get_temp_dir(), 'skernel-home-');
        self::assertIsString($home);
        self::assertTrue(unlink($home));
        putenv('SKERNEL_HOME=' . $home);
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;

        try {
            // Provision the managed runtime with the running PHP so that the build neither downloads nor compiles.
            $runtime = new RuntimeProvider(
                new Home(),
                new StaticPhpCli(new Home(), new ZigMirrors(new DailyCache(new Home()))),
                new ExtensionVersions(new DailyCache(new Home())),
            )->directory(
                $version,
                Extensions::required(new ProjectLocator()->locate($root)),
            );
            self::assertTrue(mkdir($runtime, 0o700, true));
            self::assertTrue(symlink(PHP_BINARY, $runtime . '/php'));
            self::assertTrue(touch($runtime . '/micro.sfx') && touch($runtime . '/manifest.json'));

            $output = $this->bootstrap(
                'build --php=' . $version . ' -d ' . escapeshellarg($root),
                $status,
                '2>/dev/null',
                'SKERNEL_HOME=' . escapeshellarg($home),
            );

            self::assertSame(0, $status);
            self::assertSame([
                'Build artifacts written to ' . $root . '/vendor/.cache.',
            ], $output);
            self::assertFileExists($root . '/vendor/.cache/packages.ser');
        } finally {
            putenv('SKERNEL_HOME');
            Directory::remove($home);
        }
    }

    public function testBuildRejectsAPhpOptionThatIsNotAVersion(): void
    {
        $output = $this->bootstrap(
            'build --php=php8.4 -d ' . escapeshellarg(dirname(__DIR__)),
            $status,
            '2>&1 >/dev/null',
        );

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'The --php option must be a PHP version such as 8.4 or 8.4.27.',
            (string) preg_replace('/\s+/', ' ', implode(' ', $output)),
        );
    }

    public function testBuildRejectsADirectoryWithoutAProject(): void
    {
        $missing = dirname(__DIR__) . '/missing';
        $output = $this->bootstrap('build -d ' . escapeshellarg($missing), $status, '2>&1 >/dev/null');
        $message = (string) preg_replace('/\s+/', ' ', implode(' ', $output));

        self::assertSame(1, $status);
        self::assertStringContainsString('The project directory ' . $missing . ' does not exist.', $message);
    }

    /** @return list<string> */
    private function bootstrap(
        string $arguments,
        ?int  &$status,
        string $redirection = '2>&1',
        string $environment = '',
    ): array
    {
        $bootstrap = escapeshellarg(dirname(__DIR__) . '/bin/bootstrap.php');
        $command = escapeshellarg(PHP_BINARY) . ' ' . $bootstrap . ' ' . $arguments . ' ' . $redirection;

        exec(('' === $environment ? '' : $environment . ' ') . $command, $output, $status);

        return $output;
    }
}
