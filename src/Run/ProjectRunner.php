<?php

declare(strict_types=1);

namespace Src\Run;

use Src\Process\Executable;
use Src\Project\Project;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

use function file_put_contents;
use function hrtime;
use function pcntl_async_signals;
use function pcntl_signal;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;
use function var_export;

use const SIGINT;
use const SIGTERM;

/**
 * Runs a project from source in a separate PHP process, and with watching restarts it whenever its files change.
 *
 * The process runs the standard application entry sequence in the project directory. A watched application that exits
 * on its own is started again by the next change, so that a fix restarts one that crashed. SIGINT and SIGTERM stop the
 * application first, so that it does not outlive SKERNEL.
 */
final readonly class ProjectRunner
{
    private const string ENTRY = <<<'PHP'
        <?php

        declare(strict_types=1);

        require %s;

        (static fn(): never => SuperKernel\Context\ApplicationContext::getContainer()
            ->get(SuperKernel\Contract\ApplicationInterface::class)
            ->run())();

        PHP;

    /** Microseconds between checks of the running process */
    private const int POLL = 100_000;

    /** Nanoseconds between snapshots of the project files */
    private const int WATCH_INTERVAL = 1_000_000_000;

    /** Seconds that a stopped application has to exit before it is killed */
    private const int STOP_TIMEOUT = 10;

    /**
     * @param string       $phpBinary A path or a name looked up in PATH
     * @param list<string> $arguments The arguments of the application
     *
     * @return int The exit status of the application
     *
     * @throws RuntimeException When the PHP binary is not found or cannot be started, or the temporary entry script
     *                          cannot be written
     */
    public function run(
        Project         $project,
        string          $phpBinary,
        array           $arguments,
        bool            $watch,
        OutputInterface $output,
        OutputInterface $errorOutput,
    ): int
    {
        $command = [
            Executable::find($phpBinary, 'PHP'),
        ];
        $script = tempnam(sys_get_temp_dir(), 'skernel-');

        if (false === $script) {
            throw new RuntimeException('Unable to create a temporary PHP script.');
        }

        try {
            $entry = sprintf(self::ENTRY, var_export($project->vendorDirectory . '/autoload.php', true));

            if (false === file_put_contents($script, $entry)) {
                throw new RuntimeException(sprintf('Unable to write the temporary PHP script %s.', $script));
            }

            $start = static function () use ($command, $script, $arguments, $project, $output, $errorOutput): Process {
                $process = new Process([
                    ...$command,
                    $script,
                    ...$arguments,
                ], $project->directory, null, null, null);
                $forward = null;

                if (Process::isTtySupported()) {
                    $process->setTty(true);
                } else {
                    $forward = static function (string $type, string $buffer) use ($output, $errorOutput): void {
                        (Process::ERR === $type ? $errorOutput : $output)->write(
                            $buffer,
                            false,
                            OutputInterface::OUTPUT_RAW,
                        );
                    };
                }

                try {
                    $process->start($forward);
                } catch (ProcessStartFailedException $exception) {
                    throw new RuntimeException(
                        sprintf('Unable to start the PHP binary %s.', $command[0]),
                        0,
                        $exception,
                    );
                }

                return $process;
            };
            $stopping = false;
            pcntl_async_signals(true);

            foreach ([
                SIGINT,
                SIGTERM,
            ] as $signal) {
                pcntl_signal($signal, static function () use (&$stopping): void {
                    $stopping = true;
                });
            }

            $process = $start();
            $snapshot = $watch ? ProjectFiles::snapshot($project) : [];
            $checked = hrtime(true);
            $reported = false;

            while (true) {
                $running = $process->isRunning();

                if ($stopping) {
                    $process->stop(self::STOP_TIMEOUT);

                    return $process->getExitCode() ?? 1;
                }

                if (!$running && !$watch) {
                    return $process->getExitCode() ?? 1;
                }

                if (!$running && !$reported) {
                    $errorOutput->writeln(sprintf(
                        'The application exited with status %d; waiting for a change.',
                        $process->getExitCode() ?? 1,
                    ), OutputInterface::OUTPUT_RAW);
                    $reported = true;
                }

                if ($watch && self::WATCH_INTERVAL <= hrtime(true) - $checked) {
                    $checked = hrtime(true);
                    $current = ProjectFiles::snapshot($project);

                    if ($current !== $snapshot) {
                        $snapshot = $current;
                        $errorOutput->writeln('The project changed; restarting.', OutputInterface::OUTPUT_RAW);
                        $process->stop(self::STOP_TIMEOUT);
                        $process = $start();
                        $reported = false;
                    }
                }

                usleep(self::POLL);
            }
        } finally {
            unlink($script);
        }
    }
}
