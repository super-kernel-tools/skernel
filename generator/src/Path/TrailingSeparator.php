<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Path;

use function rtrim;

/**
 * Removes trailing separators from slash-normalized paths while keeping the filesystem root intact.
 *
 * @internal
 */
final class TrailingSeparator
{
    public static function strip(string $path): string
    {
        return '/' === $path ? $path : rtrim($path, '/');
    }
}
