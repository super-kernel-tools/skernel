<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Symfony\Component\Console\Exception\RuntimeException;

use function array_filter;
use function explode;
use function is_array;
use function is_string;
use function rtrim;
use function shuffle;
use function str_starts_with;
use function trim;

/**
 * Downloads Zig release archives from the Zig community mirrors, as the Zig Software Foundation asks tools to, and
 * from ziglang.org when no mirror serves a verified copy.
 *
 * Mirrors are tried in random order so that no single mirror carries every download, and one that averages less than
 * a minimum speed gives way to the next, since their speeds differ widely. An archive is accepted only when it matches
 * its pinned SHA-256 digest, so a mirror can neither alter nor replace it. The mirror list is cached in the SKERNEL
 * home for a day, and an older list is used when ziglang.org cannot be reached.
 */
final readonly class ZigMirrors
{
    private const string LIST = 'https://ziglang.org/download/community-mirrors.txt';

    private const string RELEASES = 'https://ziglang.org/download/';

    /** Bytes per second, at which a release archive takes about four minutes */
    private const int MINIMUM_SPEED = 262144;

    public function __construct(private DailyCache $cache)
    {
    }

    /**
     * @param string $asset The file name of the release archive, such as zig-x86_64-linux-0.17.1.tar.xz
     *
     * @throws RuntimeException When neither a mirror nor ziglang.org serves the archive with its digest, the proxy
     *                          variable is invalid, or the file cannot be published
     */
    public function download(string $version, string $asset, string $target, string $sha256): void
    {
        try {
            $mirrors = $this->cache->get('zig-mirrors', 'community', static function (): array {
                $mirrors = [];

                foreach (explode("\n", Download::contents(self::LIST)) as $line) {
                    $mirror = rtrim(trim($line), '/');

                    if (str_starts_with($mirror, 'https://')) {
                        $mirrors[] = $mirror;
                    }
                }

                return $mirrors;
            });
        } catch (RuntimeException) {
            $mirrors = [];
        }

        $mirrors = is_array($mirrors) ? array_filter($mirrors, is_string(...)) : [];
        shuffle($mirrors);

        foreach ($mirrors as $mirror) {
            try {
                Download::file($mirror . '/' . $asset . '?source=skernel', $target, $sha256, self::MINIMUM_SPEED);

                return;
            } catch (RuntimeException) {
                // A mirror that cannot be read, is too slow, or serves another archive gives way to the next one.
            }
        }

        Download::file(self::RELEASES . $version . '/' . $asset, $target, $sha256);
    }
}
