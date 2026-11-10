<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Toolchain;

use PHPUnit\Framework\TestCase;
use Src\Filesystem\Directory;
use Src\Toolchain\DailyCache;
use Src\Toolchain\Home;
use Src\Toolchain\Platform;
use Src\Toolchain\StaticPhpCli;
use Src\Toolchain\ZigMirrors;

use function chmod;
use function dirname;
use function file_put_contents;
use function getenv;
use function is_string;
use function mkdir;
use function putenv;
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

    protected function executable(string $path, string $script): void
    {
        @mkdir(dirname($path), 0o700, true);
        self::assertNotFalse(file_put_contents($path, "#!/bin/sh\n" . $script . "\n"));
        self::assertTrue(chmod($path, 0o755));
    }
}
