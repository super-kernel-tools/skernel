<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Toolchain;

use Src\Toolchain\ExtensionRequirements;
use Src\Toolchain\Platform;
use Src\Toolchain\Runtime;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

use function basename;
use function dirname;
use function file_put_contents;
use function glob;
use function is_executable;
use function str_repeat;

final class PrebuiltRuntimeTest extends ToolchainTestCase
{
    public function testInstallsAPublishedRuntime(): void
    {
        $runtime = $this->runtime();
        $this->publishPrebuilt($runtime, [
            'phar',
        ]);
        $output = new BufferedOutput();

        self::assertTrue($this->prebuiltRuntime()->install($runtime, new ExtensionRequirements([
            'phar',
        ]), $output));

        self::assertSame(
            'Downloading the prebuilt PHP 8.4.27 runtime for ' . Platform::current() . ".\n",
            $output->fetch(),
        );
        self::assertTrue(is_executable($runtime->php));
        self::assertTrue(is_executable($runtime->micro));
        self::assertFileExists(dirname($runtime->php) . '/manifest.json');
        self::assertSame([], glob($this->home . '/work/*'));
    }

    public function testInstallsNothingWhenNoRuntimeIsPublished(): void
    {
        $runtime = $this->runtime();
        $output = new BufferedOutput();

        self::assertFalse($this->prebuiltRuntime()->install($runtime, new ExtensionRequirements([
            'phar',
        ]), $output));

        self::assertSame('', $output->fetch());
        self::assertDirectoryDoesNotExist($this->home . '/runtimes');
        self::assertDirectoryDoesNotExist($this->home . '/work');
    }

    public function testRejectsAnArchiveThatDoesNotMatchItsPublishedDigest(): void
    {
        $runtime = $this->runtime();
        $archive = $this->publishPrebuilt($runtime, [
            'phar',
        ]);
        self::assertNotFalse(file_put_contents($archive . '.sha256', str_repeat('0', 64) . "\n"));

        $this->assertRejected(
            $runtime,
            new ExtensionRequirements([
                'phar',
            ]),
            'The download of file://' . $archive . ' does not match its expected checksum.',
        );
    }

    public function testRejectsADigestFileWithoutADigest(): void
    {
        $runtime = $this->runtime();
        $archive = $this->publishPrebuilt($runtime, [
            'phar',
        ]);
        self::assertNotFalse(file_put_contents($archive . '.sha256', "Not Found\n"));

        $this->assertRejected(
            $runtime,
            new ExtensionRequirements([
                'phar',
            ]),
            'file://' . $archive . '.sha256 holds no SHA-256 digest.',
        );
    }

    public function testRejectsARuntimeWithOtherExtensionsThanRequested(): void
    {
        $runtime = $this->runtime();
        $archive = $this->publishPrebuilt($runtime, [
            'phar',
            'swoole',
        ]);

        $this->assertRejected(
            $runtime,
            new ExtensionRequirements([
                'phar',
            ]),
            'The prebuilt runtime ' . basename($archive) . ' does not match its manifest or the requested PHP 8.4.27 '
            . 'for ' . Platform::current() . '.',
        );
    }

    public function testRejectsARuntimeWhoseBinaryDiffersFromItsManifest(): void
    {
        $runtime = $this->runtime();
        $archive = $this->publishPrebuilt($runtime, [
            'phar',
        ], [
            'sha256' => [
                'php'       => str_repeat('0', 64),
                'micro.sfx' => str_repeat('0', 64),
            ],
        ]);

        $this->assertRejected(
            $runtime,
            new ExtensionRequirements([
                'phar',
            ]),
            'The prebuilt runtime ' . basename($archive) . ' does not match its manifest or the requested PHP 8.4.27 '
            . 'for ' . Platform::current() . '.',
        );
    }

    public function testRejectsARuntimeWhosePhpDoesNotReportTheRequestedVersion(): void
    {
        $runtime = $this->runtime();
        $this->publishPrebuilt($runtime, [
            'phar',
        ], [], '8.4.26');

        $this->assertRejected(
            $runtime,
            new ExtensionRequirements([
                'phar',
            ]),
            'The prebuilt PHP 8.4.27 runtime does not run on this machine; set "prebuilt" to false in skernel.json '
            . 'to build it here.',
        );
    }

    public function testRejectsAnExtensionVersionThatTheProjectDoesNotAccept(): void
    {
        $runtime = $this->runtime();
        $this->publishPrebuilt($runtime, [
            'swoole',
        ], [], null, '6.3.0');

        $this->assertRejected(
            $runtime,
            new ExtensionRequirements([
                'swoole',
            ], [
                'swoole' => [
                    '>=6.2.3 <6.3',
                ],
            ]),
            'static-php-cli built swoole 6.3.0, which the project does not accept: >=6.2.3 <6.3.',
        );
    }

    /** Expects that the published runtime is refused with the message and that nothing of it stays behind */
    private function assertRejected(Runtime $runtime, ExtensionRequirements $extensions, string $message): void
    {
        try {
            $this->prebuiltRuntime()->install($runtime, $extensions, new BufferedOutput());
            self::fail('A prebuilt runtime that cannot be verified must not be installed.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->home . '/runtimes');
        self::assertSame([], glob($this->home . '/work/*'));
    }

    private function runtime(): Runtime
    {
        $directory = $this->home . '/runtimes/' . Platform::current() . '/php-8.4.27/0123456789abcdef';

        return new Runtime('8.4.27', $directory . '/php', $directory . '/micro.sfx');
    }
}
