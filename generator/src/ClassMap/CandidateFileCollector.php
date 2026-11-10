<?php

declare(strict_types=1);

namespace SKERNEL\Generator\ClassMap;

use SKERNEL\Generator\Autoload\ScanRule;
use SKERNEL\Generator\Autoload\ScanRuleSet;
use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Path\FilesystemPath;

use function is_file;
use function is_string;
use function ltrim;
use function realpath;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

/**
 * Records an existing package-contained candidate file with its scan rule unless the rule set excludes it.
 *
 * @internal
 */
final class CandidateFileCollector
{
    /**
     * @param array<string, list<ScanRule>> $files Candidate files and their matching rules, updated in place
     *
     * @throws GeneratorException When the file resolves outside the package root or to the root itself
     */
    public static function collect(
        string      $file,
        string      $packageRoot,
        ScanRuleSet $scanRules,
        ScanRule    $rule,
        array      &$files,
    ): void
    {
        $canonicalFile = realpath($file);

        if (!is_string($canonicalFile) || !is_file($canonicalFile)) {
            return;
        }

        $canonicalFile = str_replace('\\', '/', $canonicalFile);

        if (!FilesystemPath::isWithin($canonicalFile, $packageRoot) || $canonicalFile === $packageRoot) {
            throw new GeneratorException(sprintf(
                'Class scan file %s is outside package root %s.',
                $canonicalFile,
                $packageRoot,
            ));
        }

        if ($scanRules->isExcluded(ltrim(substr($canonicalFile, strlen($packageRoot)), '/'))) {
            return;
        }

        $files[$canonicalFile][] = $rule;
    }
}
