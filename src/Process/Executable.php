<?php

declare(strict_types=1);

namespace Src\Process;

use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

use function is_executable;
use function is_file;
use function sprintf;

/**
 * Resolves a binary given as a path or as a name looked up in PATH.
 */
final class Executable
{
    /**
     * @param string $label The binary's name in messages, such as PHP or Composer
     *
     * @throws RuntimeException When the binary is not found
     */
    public static function find(string $binary, string $label): string
    {
        $executable = is_file($binary) && is_executable($binary) ? $binary : new ExecutableFinder()->find($binary);

        if (null === $executable) {
            throw new RuntimeException(sprintf('The %s binary %s was not found.', $label, $binary));
        }

        return $executable;
    }
}
