<?php

declare(strict_types=1);

namespace Src\Process;

use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

use function sprintf;

/**
 * Runs a command without a timeout and forwards everything it writes.
 */
final class ProcessRunner
{
    /**
     * @param non-empty-list<string> $command
     * @param string                 $description What the command runs in messages, such as "the PHP binary php"
     * @param array<string, string>  $environment Variables added to the inherited environment
     *
     * @return int The exit status of the command
     *
     * @throws RuntimeException When the command cannot be started
     */
    public static function run(
        array           $command,
        string          $directory,
        OutputInterface $output,
        string          $description,
        array           $environment = [],
    ): int
    {
        try {
            return new Process($command, $directory, $environment, null, null)->run(
                static function (string $type, string $buffer) use ($output): void {
                    $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
                },
            );
        } catch (ProcessStartFailedException $exception) {
            throw new RuntimeException(sprintf('Unable to start %s.', $description), 0, $exception);
        }
    }
}
