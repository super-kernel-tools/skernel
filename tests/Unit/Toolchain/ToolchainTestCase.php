<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Toolchain;

use Phar;
use PharData;
use PHPUnit\Framework\TestCase;
use Src\Filesystem\Directory;
use Src\Toolchain\DailyCache;
use Src\Toolchain\ExtensionVersions;
use Src\Toolchain\Home;
use Src\Toolchain\Platform;
use Src\Toolchain\PrebuiltRuntime;
use Src\Toolchain\Runtime;
use Src\Toolchain\StaticPhpCli;
use Src\Toolchain\ZigMirrors;

use function basename;
use function chmod;
use function dirname;
use function file_put_contents;
use function getenv;
use function hash_file;
use function is_string;
use function json_encode;
use function mkdir;
use function putenv;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Points SKERNEL_HOME at a temporary directory for each test so that no test reads or writes the user's home.
 */
abstract class ToolchainTestCase extends TestCase
{
    protected string $home;

    private string|false $previousHome;

    protected function setUp(): void
    {
        $home = tempnam(sys_get_temp_dir(), 'skernel-home-');
        self::assertIsString($home);
        self::assertTrue(unlink($home) && mkdir($home, 0o700));
        $this->home = $home;
        $this->previousHome = getenv('SKERNEL_HOME');
        putenv('SKERNEL_HOME=' . $home);
    }

    protected function tearDown(): void
    {
        putenv(is_string($this->previousHome) ? 'SKERNEL_HOME=' . $this->previousHome : 'SKERNEL_HOME');
        Directory::remove($this->home);
    }

    /** Installs a static-php-cli script and a Zig that needs no download */
    protected function staticPhpCli(string $script): StaticPhpCli
    {
        $staticPhpCli = new StaticPhpCli(new Home(), new ZigMirrors(new DailyCache(new Home())));
        $packages = $this->home . '/pkgroot/' . Platform::current();
        $this->executable($this->home . '/tools/spc/' . $staticPhpCli->version() . '/spc', $script);
        $this->executable($packages . '/zig/zig', 'echo 0.17.1');

        foreach ([
            'zig-cc',
            'zig-c++',
            'zig-ar',
            'zig-ld.lld',
            'zig-ranlib',
            'zig-objcopy',
        ] as $wrapper) {
            $this->executable($packages . '/zig/' . $wrapper, 'exit 0');
        }

        return $staticPhpCli;
    }

    /**
     * Publishes a prebuilt runtime below `releases/` in the home, where a PrebuiltRuntime built by prebuiltRuntime()
     * looks for it. Its PHP reports the given PHP version and, for the versioned extension, the given version.
     *
     * @param list<string>         $extensions The extensions that the manifest names
     * @param array<string, mixed> $manifest   Entries that replace the true ones of the manifest
     *
     * @return string The published archive
     */
    protected function publishPrebuilt(
        Runtime $runtime,
        array   $extensions,
        array   $manifest = [],
        ?string $reportedPhp = null,
        string  $reportedExtension = '6.2.4',
    ): string
    {
        $source = $this->home . '/prebuilt-source';
        $this->executable(
            $source . '/php',
            'case "$3" in *PHP_VERSION*) printf %s ' . ($reportedPhp ?? $runtime->version) . ';; '
            . '*) echo \'["' . $reportedExtension . '"]\';; esac',
        );
        self::assertNotFalse(file_put_contents($source . '/micro.sfx', 'micro'));
        self::assertNotFalse(file_put_contents($source . '/manifest.json', (string) json_encode($manifest + [
            'php'        => $runtime->version,
            'extensions' => $extensions,
            'platform'   => Platform::current(),
            'sha256'     => [
                'php'       => hash_file('sha256', $source . '/php'),
                'micro.sfx' => hash_file('sha256', $source . '/micro.sfx'),
            ],
        ])));
        $release = $this->home . '/releases/php-' . $runtime->version;
        $archive = $release . '/' . sprintf(
            'php-%s-%s-%s.tar',
            $runtime->version,
            Platform::current(),
            basename(dirname($runtime->php)),
        );
        self::assertTrue(mkdir($release, 0o700, true));
        $tar = new PharData($archive);

        foreach ([
            'php',
            'micro.sfx',
            'manifest.json',
        ] as $file) {
            $tar->addFile($source . '/' . $file, $file);
        }

        $tar->compress(Phar::GZ);
        unset($tar);
        self::assertTrue(unlink($archive));
        self::assertNotFalse(file_put_contents(
            $archive . '.gz.sha256',
            hash_file('sha256', $archive . '.gz') . '  ' . basename($archive) . ".gz\n",
        ));

        return $archive . '.gz';
    }

    /** Looks for prebuilt runtimes below `releases/` in the home instead of on the network */
    protected function prebuiltRuntime(): PrebuiltRuntime
    {
        return new PrebuiltRuntime(
            new Home(),
            new ExtensionVersions(new DailyCache(new Home())),
            'file://' . $this->home . '/releases/',
        );
    }

    protected function executable(string $path, string $script): void
    {
        @mkdir(dirname($path), 0o700, true);
        self::assertNotFalse(file_put_contents($path, "#!/bin/sh\n" . $script . "\n"));
        self::assertTrue(chmod($path, 0o755));
    }
}
