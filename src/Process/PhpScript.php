<?php

declare(strict_types=1);

namespace Src\Process;

use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

use function file_put_contents;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Runs generated PHP code from a temporary script in a separate PHP process.
 */
final class PhpScript
{
    /**
     * @param array<string, string> $settings INI settings passed with -d
     *
     * @return int The exit status of the PHP process
     *
     * @throws RuntimeException When the PHP binary is not found or cannot be started, or the script cannot be written
     */
    public static function run(
        string          $code,
        string          $phpBinary,
        array           $settings,
        string          $directory,
        OutputInterface $output,
    ): int
    {
        $command = [
            Executable::find($phpBinary, 'PHP'),
        ];

        foreach ($settings as $name => $value) {
            $command[] = '-d';
            $command[] = $name . '=' . $value;
        }

        $script = tempnam(sys_get_temp_dir(), 'skernel-');

        if (false === $script) {
            throw new RuntimeException('Unable to create a temporary PHP script.');
        }

        try {
            if (false === file_put_contents($script, $code)) {
                throw new RuntimeException(sprintf('Unable to write the temporary PHP script %s.', $script));
            }

            $command[] = $script;

            return ProcessRunner::run($command, $directory, $output, sprintf('the PHP binary %s', $phpBinary));
        } finally {
            unlink($script);
        }
    }
}
