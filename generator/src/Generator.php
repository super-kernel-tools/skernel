<?php

declare(strict_types=1);

namespace SKERNEL\Generator;

use SKERNEL\Generator\Attribute\AttributeScanner;
use SKERNEL\Generator\Attribute\ScanProgress;
use SKERNEL\Generator\Composer\ProjectReader;
use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Exception\ProtocolMismatchException;
use SKERNEL\Generator\Path\FilesystemPath;
use SKERNEL\Generator\Publish\ArtifactPublisher;
use SKERNEL\Generator\Publish\AttributeMetadataFactory;
use SKERNEL\Generator\Publish\PackageManagerFactory;
use SKERNEL\Generator\Publish\Protocol;
use Throwable;

use function count;
use function sprintf;

/**
 * Generates a project's package and annotation records in the process that has loaded the project's autoloader.
 *
 * It uses no container and no package of its own, so that it runs next to any project's code, including SKERNEL's
 * own before SKERNEL's container exists.
 */
final class Generator
{
    /**
     * Checks the protocol first; scans everything before writing anything, so that a failed scan keeps the previous
     * build.
     *
     * @param non-empty-string|null       $skernelVersion The version stamped into the build, or null for the root
     *                                                    package's version, `dev` when it declares none
     * @param array<class-string, string> $fatalClasses   Classes that ended an earlier run, with their fatal errors
     *
     * @throws ProtocolMismatchException When the project's super-kernel/metadata uses another protocol or is missing
     * @throws GeneratorException        When the project cannot be read
     * @throws Throwable                 When super-kernel/metadata or the annotator fails to publish
     */
    public static function generate(
        string        $projectDirectory,
        string        $vendorDirectory,
        ?string       $skernelVersion,
        array         $fatalClasses = [],
        ?ScanProgress $progress = null,
    ): GenerationResult
    {
        $project = FilesystemPath::canonicalDirectory($projectDirectory)
            ?? throw new GeneratorException(sprintf('The project directory %s does not exist.', $projectDirectory));
        $vendor = FilesystemPath::canonicalDirectory($vendorDirectory) ?? throw new GeneratorException(sprintf(
            'The vendor directory %s does not exist. Install the project\'s dependencies first.',
            $vendorDirectory,
        ));
        Protocol::assertCompatible();
        $records = ProjectReader::read($project, $vendor);
        $manager = PackageManagerFactory::create($records);
        $attributes = AttributeScanner::scan(
            $records,
            $fatalClasses,
            $progress ?? new ScanProgress(),
            AttributeMetadataFactory::create(...),
        );
        ArtifactPublisher::publish(
            $manager,
            $attributes,
            $project,
            $vendor,
            $skernelVersion ?? $records[0]->declaration->version ?? 'dev',
        );
        $classes = 0;
        $diagnostics = [];

        foreach ($records as $record) {
            $classes += count($record->classMap->classes);
        }

        foreach ($attributes as $package) {
            foreach ($package->diagnostics as $diagnostic) {
                $diagnostics[] = $diagnostic;
            }
        }

        return new GenerationResult(count($records), $classes, $diagnostics);
    }
}
