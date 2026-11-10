<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Path;

use function array_pop;
use function explode;
use function implode;
use function is_dir;
use function ltrim;
use function preg_match;
use function realpath;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;

/**
 * Normalizes local filesystem paths to slash-separated absolute form without creating them.
 *
 * @internal
 */
final class FilesystemPath
{
    /**
     * Reports whether a path starts at the filesystem root or at a Windows drive root such as `C:/`.
     */
    public static function isAbsolute(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, '/') || 1 === preg_match('~\\A[A-Za-z]:/~', $path);
    }

    /**
     * Resolves a path against a base directory and normalizes its segments.
     *
     * Existing paths are canonicalized through realpath; missing paths keep their normalized absolute form.
     *
     * @return string|null Normalized absolute path, or null for an empty, null-byte, or escaping path
     */
    public static function absolute(string $baseDirectory, string $path): ?string
    {
        if ('' === $path || str_contains($path, "\0")) {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        $candidate = self::isAbsolute($path)
            ? $path
            : rtrim(str_replace('\\', '/', $baseDirectory), '/') . '/' . $path;
        $segments = [];

        foreach (explode('/', ltrim($candidate, '/')) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                if ([] === $segments) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalized = '/' . implode('/', $segments);
        $resolved = realpath($normalized);

        return false === $resolved ? $normalized : TrailingSeparator::strip(str_replace('\\', '/', $resolved));
    }

    /**
     * @return string|null Canonical directory using `/` separators, or null when the path is not an existing directory
     */
    public static function canonicalDirectory(?string $path): ?string
    {
        if (null === $path || '' === $path || str_contains($path, "\0")) {
            return null;
        }

        $resolved = realpath($path);

        if (false === $resolved || !is_dir($resolved)) {
            return null;
        }

        return TrailingSeparator::strip(str_replace('\\', '/', $resolved));
    }

    public static function isWithin(string $path, string $directory): bool
    {
        $path = TrailingSeparator::strip(str_replace('\\', '/', $path));
        $directory = TrailingSeparator::strip(str_replace('\\', '/', $directory));

        if ($path === $directory) {
            return true;
        }

        return '/' === $directory ? str_starts_with($path, '/') : str_starts_with($path, $directory . '/');
    }
}
