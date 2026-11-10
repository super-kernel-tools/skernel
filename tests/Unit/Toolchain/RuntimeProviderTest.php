<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Toolchain;

use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Src\Toolchain\DailyCache;
use Src\Toolchain\ExtensionRequirements;
use Src\Toolchain\ExtensionVersions;
use Src\Toolchain\Home;
use Src\Toolchain\Platform;
use Src\Toolchain\Runtime;
use Src\Toolchain\RuntimeProvider;
use Src\Toolchain\StaticPhpCli;
use Src\Toolchain\ZigMirrors;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

use function dirname;
use function file;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_array;
use function json_decode;
use function json_encode;
use function mkdir;
use function time;
use function touch;

use const FILE_IGNORE_NEW_LINES;

final class RuntimeProviderTest extends ToolchainTestCase
{
    public function testNamesRuntimesByPlatformVersionAndExtensions(): void
    {
        $provider = $this->provider();
        $directory = $provider->directory('8.4.27', new ExtensionRequirements([
            'phar',
            'swoole',
        ]));

        self::assertSame($this->home . '/runtimes/' . Platform::current() . '/php-8.4.27', dirname($directory));
        self::assertMatchesRegularExpression('/\/[0-9a-f]{16}\z/', $directory);
        self::assertNotSame($directory, $provider->directory('8.4.27', new ExtensionRequirements([
            'phar',
        ])));
    }

    public function testReusesAPublishedRuntimeWithoutRunningStaticPhpCli(): void
    {
        $provider = $this->provider();
        $directory = $provider->directory('8.4.27', new ExtensionRequirements([
            'phar',
        ]));
        self::assertTrue(mkdir($directory, 0o700, true));
        self::assertTrue(touch($directory . '/manifest.json'));
        $output = new BufferedOutput();

        self::assertEquals(
            new Runtime('8.4.27', $directory . '/php', $directory . '/micro.sfx'),
            $provider->provide('8.4.27', new ExtensionRequirements([
                'phar',
        ]), $output),
        );
        self::assertSame('', $output->fetch());
        self::assertDirectoryDoesNotExist($this->home . '/tools');
        self::assertDirectoryDoesNotExist($this->home . '/work');
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testBuildsAMicroRuntimeThatReportsTheCliSapi(): void
    {
        $this->staticPhpCli(
            'echo "$*" >> ' . $this->home . "/spc.log\n"
            . 'if [ "$1" = build ]; then mkdir -p "$BUILD_ROOT_PATH/bin"; '
            . ': > "$BUILD_ROOT_PATH/bin/php"; : > "$BUILD_ROOT_PATH/bin/micro.sfx"; fi',
        );
        $this->executable($this->home . '/pkgroot/' . Platform::current() . '/bin/pkg-config', 'exit 0');
        $provider = $this->provider();

        $runtime = $provider->provide('8.4.27', new ExtensionRequirements([
            'phar',
        ]), new BufferedOutput());

        self::assertSame([
            'download --with-php=8.4 --for-extensions=phar '
            . '--custom-url=php-src:https://www.php.net/distributions/php-8.4.27.tar.xz '
            . '--without-suggestions --retry=2',
            'build phar --build-cli --build-micro --with-micro-fake-cli',
        ], file($this->home . '/spc.log', FILE_IGNORE_NEW_LINES));
        self::assertFileExists($runtime->micro);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testBuildsTheSelectedExtensionVersionAndRecordsIt(): void
    {
        $this->build('6.2.4');
        $runtime = $this->provider()->provide('8.4.27', $this->swoole(), new BufferedOutput());

        self::assertSame([
            'download --with-php=8.4 --for-extensions=swoole '
            . '--custom-url=php-src:https://www.php.net/distributions/php-8.4.27.tar.xz '
            . '--custom-url=swoole:https://pecl.php.net/get/swoole-6.2.4.tgz '
            . '--without-suggestions --retry=2',
            'build swoole --build-cli --build-micro --with-micro-fake-cli',
        ], file($this->home . '/spc.log', FILE_IGNORE_NEW_LINES));
        $manifest = json_decode((string) file_get_contents(dirname($runtime->micro) . '/manifest.json'), true);
        self::assertSame([
            'swoole' => '6.2.4',
        ], is_array($manifest) ? $manifest['extension-versions'] : null);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testKeepsTheJobWhenTheBuiltExtensionVersionIsNotAccepted(): void
    {
        $this->build('6.3.0');

        try {
            $this->provider()->provide('8.4.27', $this->swoole(), new BufferedOutput());
            self::fail('A runtime with an extension version that the project does not accept must not be published.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'static-php-cli built swoole 6.3.0, which the project does not accept: >=6.2.3 <6.3.',
                $exception->getMessage(),
            );
        }

        self::assertDirectoryDoesNotExist($this->home . '/runtimes');
        self::assertNotSame([], glob($this->home . '/work/runtime-*'));
    }

    public function testNamesARuntimeByItsSelectedVersionsAndNotTheirConstraints(): void
    {
        $provider = $this->provider();
        $directory = $provider->directory('8.4.27', $this->swoole());

        self::assertSame($directory, $provider->directory('8.4.27', new ExtensionRequirements([
            'swoole',
        ], [
            'swoole' => [
                '^6.2',
                '<6.3',
            ],
        ])));
        self::assertNotSame($directory, $provider->directory('8.4.27', new ExtensionRequirements([
            'swoole',
        ], [
            'swoole' => [
                '<6.2.4',
            ],
        ])));
    }

    public function testNamesARuntimeByTheConstraintsOfAnExtensionWithNoSelectedVersion(): void
    {
        $provider = $this->provider();

        self::assertNotSame(
            $provider->directory('8.4.27', new ExtensionRequirements([
                'mongodb',
            ], [
                'mongodb' => [
                    '^1.0',
                ],
            ])),
            $provider->directory('8.4.27', new ExtensionRequirements([
                'mongodb',
            ], [
                'mongodb' => [
                    '^2.0',
                ],
            ])),
        );
    }

    /** Requires swoole with its PECL releases cached, so that nothing is downloaded */
    private function swoole(): ExtensionRequirements
    {
        @mkdir($this->home . '/cache', 0o700);
        self::assertNotFalse(file_put_contents($this->home . '/cache/pecl-releases.json', (string) json_encode([
            'swoole' => [
                'checked' => time(),
                'data'    => [
                    '6.3.0',
                    '6.2.4',
                    '6.2.3',
                ],
            ],
        ])));

        return new ExtensionRequirements([
            'swoole',
        ], [
            'swoole' => [
                '>=6.2.3 <6.3',
            ],
        ]);
    }

    /** Installs a recording static-php-cli whose build produces a PHP that reports the given version of swoole */
    private function build(string $version): void
    {
        $this->staticPhpCli(
            'echo "$*" >> ' . $this->home . "/spc.log\n"
            . 'if [ "$1" = build ]; then mkdir -p "$BUILD_ROOT_PATH/bin"; '
            . ': > "$BUILD_ROOT_PATH/bin/micro.sfx"; '
            . 'printf \'#!/bin/sh\necho \047["' . $version . '"]\047\n\' > "$BUILD_ROOT_PATH/bin/php"; '
            . 'chmod +x "$BUILD_ROOT_PATH/bin/php"; fi',
        );
        $this->executable($this->home . '/pkgroot/' . Platform::current() . '/bin/pkg-config', 'exit 0');
    }

    private function provider(): RuntimeProvider
    {
        return new RuntimeProvider(
            new Home(),
            new StaticPhpCli(new Home(), new ZigMirrors(new DailyCache(new Home()))),
            new ExtensionVersions(new DailyCache(new Home())),
        );
    }
}
