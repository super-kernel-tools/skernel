<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Toolchain;

use Src\Toolchain\DailyCache;
use Src\Toolchain\ExtensionRequirements;
use Src\Toolchain\ExtensionVersions;
use Src\Toolchain\Home;
use Symfony\Component\Console\Exception\RuntimeException;

use function file_put_contents;
use function json_encode;
use function mkdir;
use function phpversion;
use function time;

use const PHP_BINARY;

final class ExtensionVersionsTest extends ToolchainTestCase
{
    public function testSelectsTheLatestStableReleaseThatTheInstallationAccepts(): void
    {
        $this->releases();

        self::assertSame([
            'swoole' => '6.2.4',
        ], $this->versions()->select(new ExtensionRequirements([
            'swoole',
        ], [
            'swoole' => [
                '>=6.2.3 <6.3',
            ],
        ])));
    }

    public function testASingleConflictExcludesARelease(): void
    {
        $this->releases();

        self::assertSame([
            'swoole' => '6.2.3',
        ], $this->versions()->select(new ExtensionRequirements([
            'swoole',
        ], [
            'swoole' => [
                '^6.2',
            ],
        ], [
            'swoole' => [
                '>=6.2.4',
            ],
        ])));
    }

    public function testEveryConstraintMustHoldEvenWithAlternatives(): void
    {
        $this->releases();

        self::assertSame([
            'swoole' => '6.2.3',
        ], $this->versions()->select(new ExtensionRequirements([
            'swoole',
        ], [
            'swoole' => [
                '6.1.* || 6.2.*',
                '<6.2.4',
            ],
        ])));
    }

    public function testLeavesAnUnconstrainedOrUnpublishedExtensionToStaticPhpCli(): void
    {
        self::assertSame([], $this->versions()->select(new ExtensionRequirements([
            'mongodb',
            'swoole',
        ], [
            'mongodb' => [
                '^1.0',
            ],
        ])));
    }

    public function testRejectsAnInstallationThatAcceptsNoStableRelease(): void
    {
        $this->releases();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('PECL lists no stable release of swoole that the project accepts: >=7, not 8.');

        $this->versions()->select(new ExtensionRequirements([
            'swoole',
        ], [
            'swoole' => [
                '>=7',
            ],
        ], [
            'swoole' => [
                '8',
            ],
        ]));
    }

    public function testNamesTheArchiveOfARelease(): void
    {
        self::assertSame(
            'https://pecl.php.net/get/swoole-6.2.4.tgz',
            $this->versions()->archive('swoole', '6.2.4'),
        );
    }

    public function testReportsTheVersionsOfABuiltPhpThatTheInstallationAccepts(): void
    {
        self::assertSame([
            'tokenizer' => phpversion('tokenizer'),
        ], $this->versions()->verify(PHP_BINARY, new ExtensionRequirements([
            'absent',
            'phar',
            'tokenizer',
        ], [
            'absent' => [
                '^1.0',
            ],
            'tokenizer' => [
                '>=8',
            ],
        ])));
    }

    public function testRejectsABuiltPhpWithAVersionThatTheInstallationDoesNotAccept(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'static-php-cli built tokenizer ' . phpversion('tokenizer') . ', which the project does not accept: <8.',
        );

        $this->versions()->verify(PHP_BINARY, new ExtensionRequirements([
            'tokenizer',
        ], [
            'tokenizer' => [
                '<8',
            ],
        ]));
    }

    public function testDoesNotRunAPhpWhenNoExtensionIsVersioned(): void
    {
        self::assertSame([], $this->versions()->verify($this->home . '/missing', new ExtensionRequirements([
            'phar',
        ])));
    }

    private function versions(): ExtensionVersions
    {
        return new ExtensionVersions(new DailyCache(new Home()));
    }

    /** Caches the stable releases that PECL lists for swoole */
    private function releases(): void
    {
        self::assertTrue(mkdir($this->home . '/cache', 0o700));
        self::assertNotFalse(file_put_contents($this->home . '/cache/pecl-releases.json', (string) json_encode([
            'swoole' => [
                'checked' => time(),
                'data'    => [
                    '6.2.3',
                    '6.3.0',
                    '6.2.4',
                ],
            ],
        ])));
    }
}
