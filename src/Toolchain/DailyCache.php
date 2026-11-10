<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Closure;
use Src\Filesystem\Directory;
use Symfony\Component\Console\Exception\RuntimeException;

use function array_key_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function is_int;
use function json_decode;
use function json_encode;
use function rename;
use function sprintf;
use function tempnam;
use function time;
use function unlink;

use const JSON_UNESCAPED_SLASHES;

/**
 * Caches answers fetched over the network in the SKERNEL home for a day.
 *
 * When an answer cannot be fetched again, the older cached answer is used instead.
 */
final readonly class DailyCache
{
    private const int LIFETIME = 86400;

    public function __construct(private Home $home)
    {
    }

    /**
     * @param string           $name  The name of the cache file in the home's cache directory, without its extension
     * @param string           $key   The cache key of the answer
     * @param Closure(): mixed $fetch Fetches the answer and throws a RuntimeException when it cannot
     *
     * @throws RuntimeException When the answer cannot be fetched and nothing is cached, or the cache cannot be written
     */
    public function get(string $name, string $key, Closure $fetch): mixed
    {
        $directory = $this->home->directory() . '/cache';
        $file = $directory . '/' . $name . '.json';
        $cache = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $cache = is_array($cache) ? $cache : [];
        $entry = is_array($cache[$key] ?? null) ? $cache[$key] : null;

        if (null !== $entry && is_int($entry['checked'] ?? null) && self::LIFETIME > time() - $entry['checked']) {
            return $entry['data'] ?? null;
        }

        try {
            $data = $fetch();
        } catch (RuntimeException $exception) {
            if (null !== $entry && array_key_exists('data', $entry)) {
                return $entry['data'];
            }

            throw $exception;
        }

        $cache[$key] = [
            'checked' => time(),
            'data'    => $data,
        ];
        Directory::create($directory);
        $encoded = json_encode($cache, JSON_UNESCAPED_SLASHES);
        $temporary = @tempnam($directory, '.' . $name . '-');

        try {
            if (
                false === $encoded
                || false === $temporary
                || false === file_put_contents($temporary, $encoded)
                || !@rename($temporary, $file)
            ) {
                throw new RuntimeException(sprintf('Unable to write %s.', $file));
            }
        } finally {
            if (false !== $temporary && is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $data;
    }
}
