<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Toolchain;

use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Src\Toolchain\Platform;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

use function mkdir;
use function strlen;
use function substr;

/**
 * Runs a recording static-php-cli script with a Zig that is installed already, so that nothing is downloaded.
 */
#[RequiresOperatingSystemFamily('Linux')]
final class StaticPhpCliTest extends ToolchainTestCase
{
    private const string RECORD = 'printf "%s|%s|%s|%s\n" "$*" "$SPC_TARGET" "$PKG_ROOT_PATH" "$SOURCE_PATH"';

    public function testRunsWithTheInstalledZigForAStaticMuslTarget(): void
    {
        $packages = $this->home . '/pkgroot/' . Platform::current();
        $staticPhpCli = $this->staticPhpCli(self::RECORD);
        $this->executable($packages . '/bin/pkg-config', 'exit 0');
        $job = $this->job();
        $output = new BufferedOutput();

        self::assertSame(0, $staticPhpCli->run([
            'download',
            '--for-extensions=phar',
        ], $job, $output));
        self::assertSame(
            'download --for-extensions=phar|' . self::target() . '|' . $packages . '|' . $job . "/source\n",
            $output->fetch(),
        );
    }

    public function testInstallsPkgConfigThroughStaticPhpCliBeforeTheFirstRun(): void
    {
        $packages = $this->home . '/pkgroot/' . Platform::current();
        $staticPhpCli = $this->staticPhpCli(self::RECORD);
        $job = $this->job();
        $output = new BufferedOutput();

        self::assertSame(0, $staticPhpCli->run([
            'download',
        ], $job, $output));
        self::assertSame(
            'install-pkg pkg-config|' . self::target() . '|' . $packages . '|' . $job . "/source\n"
            . 'download|' . self::target() . '|' . $packages . '|' . $job . "/source\n",
            $output->fetch(),
        );
    }

    public function testFailsWhenPkgConfigCannotBeInstalled(): void
    {
        $staticPhpCli = $this->staticPhpCli('exit 3');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('static-php-cli could not install pkg-config (exit status 3).');

        $staticPhpCli->run([
            'download',
        ], $this->job(), new BufferedOutput());
    }

    private static function target(): string
    {
        return substr(Platform::current(), strlen('linux-')) . '-linux-musl';
    }

    private function job(): string
    {
        $job = $this->home . '/work/job';
        self::assertTrue(mkdir($job, 0o700, true));

        return $job;
    }
}
