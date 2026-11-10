<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Composer;

use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Src\Composer\ComposerLocator;
use Src\Composer\ManagedComposer;
use Src\Tests\Unit\Toolchain\ToolchainTestCase;
use Src\Toolchain\DailyCache;
use Src\Toolchain\ExtensionRequirements;
use Src\Toolchain\ExtensionVersions;
use Src\Toolchain\Home;
use Src\Toolchain\PhpReleases;
use Src\Toolchain\ReleaseIndex;
use Src\Toolchain\RuntimeProvider;
use Src\Toolchain\StaticPhpCli;
use Src\Toolchain\ZigMirrors;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

use function file_put_contents;
use function getenv;
use function is_string;
use function json_encode;
use function mkdir;
use function putenv;
use function time;
use function touch;

/**
 * Looks for Composer with the system's PATH and the user's home directory pointing at temporary directories.
 */
#[RequiresOperatingSystemFamily('Linux')]
final class ComposerLocatorTest extends ToolchainTestCase
{
    private const string VERSION_2 = 'echo "Composer version 2.10.3 2026-08-27 13:34:23"';

    private string|false $previousPath;

    private string|false $previousHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPath = getenv('PATH');
        $this->previousHome = getenv('HOME');
        putenv('PATH=' . $this->home . '/path');
        putenv('HOME=' . $this->home . '/user');
    }

    protected function tearDown(): void
    {
        putenv(is_string($this->previousPath) ? 'PATH=' . $this->previousPath : 'PATH');
        putenv(is_string($this->previousHome) ? 'HOME=' . $this->previousHome : 'HOME');
        parent::tearDown();
    }

    public function testUsesAWorkingComposerInThePath(): void
    {
        $this->executable($this->home . '/path/composer', self::VERSION_2);
        $this->executable($this->home . '/user/.local/bin/composer', self::VERSION_2);
        $output = new BufferedOutput();

        self::assertSame([
            $this->home . '/path/composer',
        ], $this->locator()->command(null, $output));
        self::assertSame('', $output->fetch());
    }

    public function testFindsAComposerPharInThePath(): void
    {
        $this->executable($this->home . '/path/composer.phar', self::VERSION_2);

        self::assertSame([
            $this->home . '/path/composer.phar',
        ], $this->locator()->command(null, new BufferedOutput()));
    }

    public function testFallsBackToTheUsersComposerWhenThePathHasNone(): void
    {
        $this->executable($this->home . '/user/.config/composer/vendor/bin/composer', self::VERSION_2);

        self::assertSame([
            $this->home . '/user/.config/composer/vendor/bin/composer',
        ], $this->locator()->command(null, new BufferedOutput()));
    }

    public function testSkipsAnOldOrBrokenComposer(): void
    {
        $this->executable($this->home . '/path/composer', 'echo "Composer version 1.10.28 2024-01-01 00:00:00"');
        $this->executable($this->home . '/path/composer.phar', 'exit 1');
        $this->executable($this->home . '/user/bin/composer', 'echo "Composer 2.11.1"');

        self::assertSame([
            $this->home . '/user/bin/composer',
        ], $this->locator()->command(null, new BufferedOutput()));
    }

    public function testUsesTheRequestedComposerWithoutCheckingIt(): void
    {
        $this->executable($this->home . '/path/composer', self::VERSION_2);
        $this->executable($this->home . '/custom', 'exit 1');

        self::assertSame([
            $this->home . '/custom',
        ], $this->locator()->command($this->home . '/custom', new BufferedOutput()));
    }

    public function testRejectsARequestedComposerThatIsNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The Composer binary missing was not found.');

        $this->locator()->command('missing', new BufferedOutput());
    }

    public function testUsesItsOwnComposerWhenNoneIsFound(): void
    {
        $this->executable($this->home . '/path/composer', 'exit 1');
        $composer = $this->home . '/tools/composer/2.11.1/composer.phar';
        self::assertTrue(mkdir($this->home . '/tools/composer/2.11.1', 0o700, true));
        self::assertTrue(touch($composer));
        self::assertTrue(mkdir($this->home . '/cache', 0o700));
        self::assertNotFalse(file_put_contents($this->home . '/cache/php-releases.json', (string) json_encode([
            '8.4' => [
                'checked' => time(),
                'data'    => [
                    'version' => '8.4.27',
                ],
            ],
        ])));
        $runtime = $this->runtimes()->directory('8.4.27', new ExtensionRequirements([
            'curl',
            'filter',
            'iconv',
            'openssl',
            'phar',
            'tokenizer',
            'zlib',
        ]));
        self::assertTrue(mkdir($runtime, 0o700, true));
        self::assertTrue(touch($runtime . '/manifest.json'));
        $output = new BufferedOutput();

        self::assertSame([
            $runtime . '/php',
            $composer,
        ], $this->locator()->command(null, $output));
        self::assertSame(
            "No working Composer 2 was found; using Composer 2.11.1 in the SKERNEL home.\n",
            $output->fetch(),
        );
    }

    private function locator(): ComposerLocator
    {
        return new ComposerLocator(new ManagedComposer(
            new Home(),
            new PhpReleases(new ReleaseIndex(new DailyCache(new Home()))),
            $this->runtimes(),
        ));
    }

    private function runtimes(): RuntimeProvider
    {
        return new RuntimeProvider(
            new Home(),
            new StaticPhpCli(new Home(), new ZigMirrors(new DailyCache(new Home()))),
            new ExtensionVersions(new DailyCache(new Home())),
        );
    }
}
