<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Toolchain;

use Src\Toolchain\DailyCache;
use Src\Toolchain\Home;
use Src\Toolchain\PhpReleases;
use Src\Toolchain\ReleaseIndex;
use Symfony\Component\Console\Exception\RuntimeException;

use function array_map;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function time;

final class PhpReleasesTest extends ToolchainTestCase
{
    public function testKeepsAnExactVersionWithoutAskingPhpNet(): void
    {
        self::assertSame('8.4.27', $this->releases()->resolve('8.4.27', '>=8.5'));
    }

    public function testResolvesAMinorVersionToItsLatestRelease(): void
    {
        $this->cache([
            '8.4' => [
                'version' => '8.4.27',
            ],
        ]);

        self::assertSame('8.4.27', $this->releases()->resolve('8.4', null));
    }

    public function testSelectsTheLatestSupportedReleaseThatSatisfiesTheRequirement(): void
    {
        $this->cache([
            'active' => [
                '8' => [
                    '8.3' => [
                        'version' => '8.3.30',
                    ],
                    '8.4' => [
                        'version' => '8.4.27',
                    ],
                    '8.5' => [
                        'version' => '8.5.12',
                    ],
                ],
            ],
        ]);

        self::assertSame('8.4.27', $this->releases()->resolve(null, '>=8.4.27 <8.5'));
        self::assertSame('8.5.12', $this->releases()->resolve(null, null));
    }

    public function testRejectsARequirementThatNoSupportedReleaseSatisfies(): void
    {
        $this->cache([
            'active' => [
                '8' => [
                    '8.4' => [
                        'version' => '8.4.27',
                    ],
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'No supported PHP release satisfies the requirement ^7.4; set "php" in skernel.json or pass --php.',
        );

        $this->releases()->resolve(null, '^7.4');
    }

    private function releases(): PhpReleases
    {
        return new PhpReleases(new ReleaseIndex(new DailyCache(new Home())));
    }

    /** @param array<string, mixed> $answers Fresh cached php.net answers by query key */
    private function cache(array $answers): void
    {
        $cache = json_encode(array_map(static fn(mixed $data): array => [
            'checked' => time(),
            'data'    => $data,
        ], $answers));
        self::assertIsString($cache);
        self::assertTrue(mkdir($this->home . '/cache'));
        self::assertNotFalse(file_put_contents($this->home . '/cache/php-releases.json', $cache));
    }
}
