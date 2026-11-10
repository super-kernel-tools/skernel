<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Autoload;

use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Path\FilesystemPath;

use function explode;
use function implode;
use function ltrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;

/**
 * Normalizes one `exclude-from-classmap` pattern.
 *
 * @internal
 */
final class ExclusionPattern
{
    /**
     * @return string The pattern without empty or `.` segments; a leading `/` keeps it anchored at the package root,
     *                and a trailing `/` keeps it limited to directories
     *
     * @throws GeneratorException When the pattern contains a null byte, a `..` segment, or an unsupported wildcard, or
     *                            is absolute
     */
    public static function normalize(string $pattern): string
    {
        if (str_contains($pattern, "\0")) {
            throw new GeneratorException('Classmap exclusion patterns must not contain null bytes.');
        }

        $pattern = str_replace('\\', '/', $pattern);
        $relativePattern = ltrim($pattern, '/');

        if (
            str_starts_with($pattern, '//')
            || FilesystemPath::isAbsolute($relativePattern)
            || AutoloadPath::hasUnsupportedWildcard($pattern)
        ) {
            throw new GeneratorException('Invalid classmap exclusion pattern.');
        }

        $segments = [];

        foreach (explode('/', $relativePattern) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                throw new GeneratorException('Classmap exclusion patterns must not contain ".." segments.');
            }

            $segments[] = $segment;
        }

        $normalized = implode('/', $segments);

        if (str_ends_with($pattern, '/') && '' !== $normalized) {
            $normalized .= '/';
        }

        return (str_starts_with($pattern, '/') ? '/' : '') . $normalized;
    }
}
