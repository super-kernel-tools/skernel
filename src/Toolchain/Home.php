<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Symfony\Component\Console\Exception\RuntimeException;

use function getenv;
use function is_string;
use function rtrim;

/**
 * Locates the SKERNEL home that holds the managed PHP toolchain: SKERNEL_HOME, or .skernel in the user's home.
 */
final readonly class Home
{
    /** @throws RuntimeException When neither SKERNEL_HOME nor the user's home directory is set */
    public function directory(): string
    {
        $override = getenv('SKERNEL_HOME');

        if (is_string($override) && '' !== $override) {
            return rtrim($override, '/\\');
        }

        $home = getenv('HOME');

        if (!is_string($home) || '' === $home) {
            $home = getenv('USERPROFILE');
        }

        if (!is_string($home) || '' === $home) {
            throw new RuntimeException('Unable to locate the home directory; set SKERNEL_HOME.');
        }

        return rtrim($home, '/\\') . '/.skernel';
    }
}
