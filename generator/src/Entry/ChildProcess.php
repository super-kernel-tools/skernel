<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Entry;

use SKERNEL\Generator\Attribute\ScanProgress;
use SKERNEL\Generator\Constants\ReportStatus;
use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Exception\ProtocolMismatchException;
use SKERNEL\Generator\Exchange\Report;
use SKERNEL\Generator\Exchange\Request;
use SKERNEL\Generator\Generator;
use Throwable;

use function count;
use function error_get_last;
use function fwrite;
use function in_array;
use function ini_set;
use function register_shutdown_function;
use function sprintf;

use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use const E_USER_ERROR;
use const STDERR;

/**
 * Runs the generator in a project's own process, as `build` starts it, and reports how it ended.
 *
 * @internal
 */
final class ChildProcess
{
    /**
     * Writes exactly one report, also when a class ends the process with a fatal error or an exit, after which PHP
     * exits with its own status; error display and logging are off because every failure reaches SKERNEL through the
     * report.
     *
     * @param list<string> $arguments The script, the request file, and the report file
     *
     * @return int 0 when the generator finished and wrote its report, 64 for a wrong invocation
     */
    public static function run(array $arguments): int
    {
        if (3 !== count($arguments)) {
            fwrite(STDERR, "Usage: generate.php <request-file> <report-file>\n");

            return 64;
        }

        [, $requestPath, $reportPath] = $arguments;
        $progress = new ScanProgress();
        $finished = false;
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        register_shutdown_function(static function () use (&$finished, $progress, $reportPath): void {
            if ($finished) {
                return;
            }

            $error = error_get_last();
            $message = null !== $error && in_array($error['type'], [
                E_ERROR,
                E_PARSE,
                E_CORE_ERROR,
                E_COMPILE_ERROR,
                E_USER_ERROR,
            ], true)
                ? $error['message']
                : 'The process exited before the build finished.';

            try {
                (null === $progress->class
                    ? new Report(ReportStatus::Failed, $message)
                    : new Report(ReportStatus::ClassFatal, $message, $progress->package, $progress->class))
                    ->write($reportPath);
            } catch (Throwable) {
                // Without a report SKERNEL reports the exit status instead.
            }
        });

        try {
            $request = Request::read($requestPath);
            require $request->vendorDirectory . '/autoload.php';
            $report = Report::built(Generator::generate(
                $request->projectDirectory,
                $request->vendorDirectory,
                $request->skernelVersion,
                $request->fatalClasses,
                $progress,
            ));
        } catch (ProtocolMismatchException $exception) {
            $report = new Report(
                ReportStatus::ProtocolMismatch,
                $exception->getMessage(),
                projectProtocol: $exception->project,
            );
        } catch (GeneratorException $exception) {
            $report = new Report(ReportStatus::Failed, $exception->getMessage());
        } catch (Throwable $exception) {
            $report = new Report(
                ReportStatus::Failed,
                sprintf('%s: %s', $exception::class, $exception->getMessage()),
            );
        }

        $finished = true;
        $report->write($reportPath);

        return 0;
    }
}
