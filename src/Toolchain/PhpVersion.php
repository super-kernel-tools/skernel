<?php

declare(strict_types=1);

namespace Src\Toolchain;

use function is_string;
use function preg_match;

/**
 * Recognizes the PHP versions that SKERNEL accepts: major.minor, such as 8.4, or major.minor.patch, such as 8.4.27.
 */
final class PhpVersion
{
    public static function isValid(mixed $version): bool
    {
        return is_string($version) && 1 === preg_match('/\A[1-9]\d*\.\d+(?:\.\d+)?\z/', $version);
    }
}
