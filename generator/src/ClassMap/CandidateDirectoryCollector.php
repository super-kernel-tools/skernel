<?php

declare(strict_types=1);

namespace SKERNEL\Generator\ClassMap;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SKERNEL\Generator\Autoload\ScanRule;
use SKERNEL\Generator\Autoload\ScanRuleSet;
use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Path\FilesystemPath;
use SplFileInfo;
use Throwable;

use function sprintf;

/**
 * Records every candidate file below an existing package-contained directory for one scan rule.
 *
 * @internal
 */
final class CandidateDirectoryCollector
{
    /**
     * @param array<string, list<ScanRule>> $files Candidate files and their matching rules, updated in place
     *
     * @throws GeneratorException When the directory resolves outside the package root or cannot be scanned
     */
    public static function collect(
        string      $directory,
        string      $packageRoot,
        ScanRuleSet $scanRules,
        ScanRule    $rule,
        array      &$files,
    ): void
    {
        $canonicalDirectory = FilesystemPath::canonicalDirectory($directory);

        if (null === $canonicalDirectory) {
            return;
        }

        if (!FilesystemPath::isWithin($canonicalDirectory, $packageRoot)) {
            throw new GeneratorException(sprintf(
                'Class scan directory %s is outside package root %s.',
                $canonicalDirectory,
                $packageRoot,
            ));
        }

        try {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($canonicalDirectory, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $entry */
            foreach ($entries as $entry) {
                if ($entry->isFile()) {
                    CandidateFileCollector::collect($entry->getPathname(), $packageRoot, $scanRules, $rule, $files);
                }
            }
        } catch (GeneratorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new GeneratorException(
                sprintf('Unable to scan class directory %s.', $canonicalDirectory),
                previous: $exception,
            );
        }
    }
}
