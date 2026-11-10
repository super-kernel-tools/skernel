<?php

declare(strict_types=1);

namespace Src\Toolchain;

use JsonException;
use Symfony\Component\Console\Exception\RuntimeException;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Answers queries against the php.net release index, caching each answer in the SKERNEL home for a day.
 *
 * When php.net cannot be reached, an older cached answer is used instead.
 */
final readonly class ReleaseIndex
{
    private const string ENDPOINT = 'https://www.php.net/releases/';

    public function __construct(private DailyCache $cache)
    {
    }

    /**
     * @param string $key   The cache key of the query
     * @param string $query The path and query below the release endpoint
     *
     * @throws RuntimeException When php.net cannot be read and nothing is cached, or the cache cannot be written
     */
    public function get(string $key, string $query): mixed
    {
        return $this->cache->get('php-releases', $key, static function () use ($query): mixed {
            try {
                return json_decode(Download::contents(self::ENDPOINT . $query), true, 512, JSON_THROW_ON_ERROR);
            } catch (RuntimeException|JsonException $exception) {
                throw new RuntimeException('Unable to read the PHP release index from php.net.', 0, $exception);
            }
        });
    }
}
