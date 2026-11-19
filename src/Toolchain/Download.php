<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Src\Toolchain\Exception\DownloadNotFoundException;
use Symfony\Component\Console\Exception\RuntimeException;

use function dirname;
use function fclose;
use function feof;
use function file_exists;
use function file_get_contents;
use function fopen;
use function hash_equals;
use function hash_file;
use function hrtime;
use function http_clear_last_response_headers;
use function http_get_last_response_headers;
use function is_array;
use function is_file;
use function is_string;
use function parse_url;
use function preg_match;
use function rename;
use function sleep;
use function sprintf;
use function stream_context_create;
use function stream_copy_to_stream;
use function stream_get_meta_data;
use function tempnam;
use function unlink;

use const PHP_URL_PATH;
use const PHP_URL_SCHEME;

/**
 * Downloads over HTTPS through the proxy the environment names, following redirects, and publishes verified files by
 * atomic rename.
 */
final class Download
{
    private const int CHUNK = 65536;

    private const int ATTEMPTS = 3;

    private const array HTTP_OPTIONS = [
        'follow_location' => 1,
        'timeout'         => 60,
        'user_agent'      => 'skernel',
    ];

    /**
     * Reads a URL. An HTTP request that receives no response or a server error is tried again, after one second and
     * then after two, up to three attempts in all; an answer such as a missing file is final.
     *
     * @throws DownloadNotFoundException When the server answers that the URL does not exist, or a local file is
     *                                   missing
     * @throws RuntimeException          When the URL cannot be read or the proxy variable is invalid
     */
    public static function contents(string $url): string
    {
        $context = stream_context_create([
            'http' => self::HTTP_OPTIONS + HttpProxy::options($url),
        ]);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $http = 'http' === $scheme || 'https' === $scheme;

        for ($attempt = 1; ; ++$attempt) {
            http_clear_last_response_headers();
            $contents = @file_get_contents($url, false, $context);

            if (false !== $contents) {
                return $contents;
            }

            $status = self::status(http_get_last_response_headers());
            $path = (string) parse_url($url, PHP_URL_PATH);

            if ($http ? 404 === $status || 410 === $status : !file_exists($path)) {
                throw new DownloadNotFoundException(sprintf('Unable to download %s.', $url));
            }

            if (!$http || self::ATTEMPTS <= $attempt || (null !== $status && 500 > $status)) {
                throw new RuntimeException(sprintf('Unable to download %s.', $url));
            }

            sleep($attempt);
        }
    }

    /**
     * Writes the download to a temporary file beside the target and renames it once its SHA-256 digest matches.
     *
     * @param int $minimumSpeed The bytes per second the download must average once $graceSeconds have passed, or 0 for
     *                          no minimum
     *
     * @throws RuntimeException When the URL cannot be read, the proxy variable is invalid, the download is too slow or
     *                          ends before the length the server announced, the digest differs, or the file cannot be
     *                          published
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
                $chunk = @stream_copy_to_stream($source, $destination, self::CHUNK);

                if (false === $chunk) {
                    break;
                }

                $copied += $chunk;
                $elapsed = (hrtime(true) - $started) / 1e9;

                if (0 < $minimumSpeed && $graceSeconds <= $elapsed && $copied < $minimumSpeed * $elapsed) {
                    throw new RuntimeException(sprintf('The download of %s is too slow.', $url));
                }
            } while (0 < $chunk);

            $length = self::length(stream_get_meta_data($source)['wrapper_data'] ?? null);
            $complete = false !== $chunk && (null === $length ? feof($source) : $length <= $copied);
            fclose($source);
            fclose($destination);

            if (!$complete) {
                throw new RuntimeException(null === $length ? sprintf(
                    'The download of %s was interrupted after %.1f MB.',
                    $url,
                    $copied / 1e6,
                ) : sprintf(
                    'The download of %s was interrupted after %.1f MB of %.1f MB.',
                    $url,
                    $copied / 1e6,
                    $length / 1e6,
                ));
            }

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

    /**
     * @param list<string>|null $headers The response headers of every response that a request followed, in order
     *
     * @return int|null The status code of the last response, or null when none was received
     */
    private static function status(?array $headers): ?int
    {
        $status = null;

        foreach ($headers ?? [] as $header) {
            if (1 === preg_match('~\AHTTP/\S+\s+(\d{3})~', $header, $match)) {
                $status = (int) $match[1];
            }
        }

        return $status;
    }

    /**
     * @param mixed $headers The wrapper data of an open stream, which an HTTP stream fills with the response headers
     *                       of every response it followed
     *
     * @return int|null The Content-Length of the last response, or null when it announced none
     */
    private static function length(mixed $headers): ?int
    {
        $length = null;

        foreach (is_array($headers) ? $headers : [] as $header) {
            if (!is_string($header)) {
                continue;
            }

            if (1 === preg_match('~\AHTTP/~', $header)) {
                $length = null;
            } elseif (1 === preg_match('~\AContent-Length:\s*(\d+)\s*\z~i', $header, $match)) {
                $length = (int) $match[1];
            }
        }

        return $length;
    }
}
