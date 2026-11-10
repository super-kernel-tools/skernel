<?php

declare(strict_types=1);

namespace Src\Build;

use SKERNEL\Generator\Constants\ReportStatus;
use SKERNEL\Generator\Diagnostic\Diagnostic;
use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Exception\ProtocolMismatchException;
use SKERNEL\Generator\Exchange\Report;
use SKERNEL\Generator\Exchange\Request;
use SKERNEL\Generator\Publish\Protocol;
use Src\Process\Executable;
use Src\Process\ProcessRunner;
use Src\Project\Project;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

use function file_put_contents;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Runs the generator in the project's own PHP process, so that SKERNEL never loads the project's code.
 */
final readonly class MetadataGenerator
{
    public function __construct(private GeneratorLocator $generatorLocator)
    {
    }

    /**
     * Runs the generator again without each class that ends its process with a fatal error, and forwards whatever
     * the process writes.
     *
     * @param non-empty-string $skernelVersion
     *
     * @return list<Diagnostic> The skipped classes in scan order
     *
     * @throws RuntimeException When the PHP binary is not found, the project's build protocol differs, or the build
     *                          fails
     */
    public function generate(
        Project         $project,
        string          $phpBinary,
        string          $skernelVersion,
        OutputInterface $output,
    ): array
    {
        $command = [
            Executable::find($phpBinary, 'PHP'),
            $this->generatorLocator->directory() . '/generate.php',
        ];
        $request = tempnam(sys_get_temp_dir(), 'skernel-request-');
        $reportPath = tempnam(sys_get_temp_dir(), 'skernel-report-');
        $fatalClasses = [];

        if (false === $request || false === $reportPath) {
            throw new RuntimeException('Unable to create the temporary files of the build.');
        }

        try {
            while (true) {
                new Request(
                    $project->directory,
                    $project->vendorDirectory,
                    $skernelVersion,
                    $fatalClasses,
                )->write($request);

                if (false === file_put_contents($reportPath, '')) {
                    throw new RuntimeException(sprintf('Unable to reset the build report %s.', $reportPath));
                }

                $status = ProcessRunner::run(
                    [
                        ...$command,
                        $request,
                        $reportPath,
                    ],
                    $project->directory,
                    $output,
                    sprintf('the PHP binary %s', $phpBinary),
                );
                $report = Report::read($reportPath)
                    ?? throw new RuntimeException(sprintf('The build ended with exit status %d.', $status));

                switch ($report->status) {
                    case ReportStatus::Built:
                        return $report->diagnostics;

                    case ReportStatus::ProtocolMismatch:
                        throw new RuntimeException(
                            new ProtocolMismatchException($report->projectProtocol, Protocol::supported())
                                ->getMessage(),
                        );

                    case ReportStatus::ClassFatal:
                        if (null === $report->class || isset($fatalClasses[$report->class])) {
                            throw new RuntimeException(sprintf(
                                'The build ended with a fatal error: %s',
                                $report->message,
                            ));
                        }

                        $fatalClasses[$report->class] = (string) $report->message;

                        break;

                    case ReportStatus::Failed:
                        throw new RuntimeException(sprintf('The build failed: %s', $report->message));
                }
            }
        } catch (GeneratorException $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        } finally {
            @unlink($request);
            @unlink($reportPath);
        }
    }
}
