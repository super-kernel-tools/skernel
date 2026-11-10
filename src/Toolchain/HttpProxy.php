<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Symfony\Component\Console\Exception\RuntimeException;

use function base64_encode;
use function explode;
use function getenv;
use function ltrim;
use function parse_url;
use function rawurldecode;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function strtolower;
use function strtoupper;
use function trim;

use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * Reads the proxy for a URL from the environment as curl does: `https_proxy` or `HTTPS_PROXY` for HTTPS,
 * `http_proxy` or `HTTP_PROXY` for HTTP, and `no_proxy` or `NO_PROXY` for the hosts that bypass it.
 *
 * A proxy URL without a scheme is an HTTP proxy. A `no_proxy` entry matches its host and every subdomain, and `*`
 * matches every host.
 */
final class HttpProxy
{
    /**
     * @return array<string, mixed> The HTTP stream context options that route the URL through its proxy, if any
     *
     * @throws RuntimeException When the proxy variable does not hold a valid proxy URL
     */
    public static function options(string $url): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $variable = $scheme . '_proxy';
        $proxy = trim((string) getenv($variable));

        if ('' === $proxy) {
            $variable = strtoupper($variable);
            $proxy = trim((string) getenv($variable));
        }

        if ('' === $proxy || '' === $host || self::bypasses($host)) {
            return [];
        }

        $parts = parse_url(str_contains($proxy, '://') ? $proxy : '//' . $proxy);
        $proxyScheme = false === $parts ? '' : strtolower($parts['scheme'] ?? 'http');

        if (!isset($parts['host']) || ('http' !== $proxyScheme && 'https' !== $proxyScheme)) {
            throw new RuntimeException(sprintf('The %s environment variable is not a valid proxy URL.', $variable));
        }

        $options = [
            'proxy' => sprintf(
                '%s://%s:%d',
                'https' === $proxyScheme ? 'ssl' : 'tcp',
                $parts['host'],
                $parts['port'] ?? ('https' === $proxyScheme ? 443 : 80),
            ),
            'request_fulluri' => 'http' === $scheme,
        ];

        if (isset($parts['user'])) {
            $options['header'] = 'Proxy-Authorization: Basic ' . base64_encode(
                rawurldecode($parts['user']) . ':' . rawurldecode($parts['pass'] ?? ''),
            );
        }

        return $options;
    }

    private static function bypasses(string $host): bool
    {
        $patterns = (string) (getenv('no_proxy') ?: getenv('NO_PROXY'));

        foreach (explode(',', $patterns) as $pattern) {
            $pattern = strtolower(ltrim(trim($pattern), '.'));

            if ('*' === $pattern || ('' !== $pattern && ($host === $pattern || str_ends_with($host, '.' . $pattern)))) {
                return true;
            }
        }

        return false;
    }
}
