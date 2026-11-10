<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Symfony\Component\Console\Exception\RuntimeException;

use function dirname;
use function fclose;
use function file_get_contents;
use function fopen;
use function hash_equals;
use function hash_file;
use function hrtime;
use function is_file;
use function rename;
use function sprintf;
use function stream_context_create;
use function stream_copy_to_stream;
use function tempnam;
use function unlink;

/**
 * Downloads over HTTPS through the proxy the environment names, following redirects, and publishes verified files by
 * atomic rename.
 */
final class Download
{
    private const int CHUNK = 65536;

    private const array HTTP_OPTIONS = [
        'follow_location' => 1,
        'timeout'         => 60,
        'user_agent'      => 'skernel',
    ];

    /** @throws RuntimeException When the URL cannot be read or the proxy variable is invalid */
    public static function contents(string $url): string
    {
        $contents = @file_get_contents($url, false, stream_context_create([
            'http' => self::HTTP_OPTIONS + HttpProxy::options($url),
        ]));

        if (false === $contents) {
            throw new RuntimeException(sprintf('Unable to download %s.', $url));
        }

        return $contents;
    }

    /**
     * Writes the download to a temporary file beside the target and renames it once its SHA-256 digest matches.
     *
     * @param int $minimumSpeed The bytes per second the download must average once $graceSeconds have passed, or 0 for
     *                          no minimum
     *
     * @throws RuntimeException When the URL cannot be read, the proxy variable is invalid, the download is too slow,
     *                          the digest differs, or the file cannot be published
     */
    public static function file(
        string $url,
        string $target,
        string $sha256,
        int    $minimumSpeed = 0,
        int    $graceSeconds = 10,
    ): void
    {
        $temporary = @tempnam(dirname($target), '.download-');

        if (false === $temporary) {
            throw new RuntimeException(sprintf('Unable to create a temporary file beside %s.', $target));
        }

        try {
            $started = hrtime(true);
            $source = @fopen($url, 'rb', false, stream_context_create([
                'http' => self::HTTP_OPTIONS + HttpProxy::options($url),
            ]));
            $destination = @fopen($temporary, 'wb');

            if (false === $source || false === $destination) {
                throw new RuntimeException(sprintf('Unable to download %s.', $url));
            }

            $copied = 0;

            do {
                $chunk = stream_copy_to_stream($source, $destination, self::CHUNK);

                if (false === $chunk) {
                    throw new RuntimeException(sprintf('Unable to download %s.', $url));
                }

                $copied += $chunk;
                $elapsed = (hrtime(true) - $started) / 1e9;

                if (0 < $minimumSpeed && $graceSeconds <= $elapsed && $copied < $minimumSpeed * $elapsed) {
                    throw new RuntimeException(sprintf('The download of %s is too slow.', $url));
                }
            } while (0 < $chunk);

            fclose($source);
            fclose($destination);

            if (!hash_equals($sha256, (string) hash_file('sha256', $temporary))) {
                throw new RuntimeException(sprintf('The download of %s does not match its expected checksum.', $url));
            }

            if (!@rename($temporary, $target)) {
                throw new RuntimeException(sprintf('Unable to write %s.', $target));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
