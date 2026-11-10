<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Symfony\Component\Console\Exception\RuntimeException;

use function php_uname;
use function sprintf;
use function strtolower;

use const PHP_OS_FAMILY;

/**
 * Names the operating system and architecture that managed runtimes are built for, such as linux-x86_64.
 */
final class Platform
{
    /** @throws RuntimeException When static-php-cli does not support the operating system or architecture */
    public static function current(): string
    {
        $system = match (PHP_OS_FAMILY) {
            'Linux'   => 'linux',
            'Darwin'  => 'macos',
            'Windows' => 'windows',
            default   => throw new RuntimeException(sprintf('SKERNEL cannot build PHP on %s.', PHP_OS_FAMILY)),
        };
        $machine = strtolower(php_uname('m'));
        $architecture = match ($machine) {
            'x86_64', 'amd64'  => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default            => null,
        };

        if (null === $architecture) {
            throw new RuntimeException(sprintf('SKERNEL cannot build PHP for the %s architecture.', $machine));
        }

        return $system . '-' . $architecture;
    }
}
