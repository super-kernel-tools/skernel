<?php

declare(strict_types=1);

namespace SKERNEL\Generator\ClassMap;

use SKERNEL\Generator\Autoload\ScanRuleSet;
use SKERNEL\Generator\Constants\ScanRuleKind;
use SKERNEL\Generator\Constants\ScanRuleSource;
use SKERNEL\Generator\Exception\GeneratorException;

use function array_keys;
use function file_get_contents;
use function glob;
use function is_dir;
use function is_file;
use function ksort;
use function sort;
use function sprintf;
use function str_starts_with;

use const GLOB_NOSORT;
use const SORT_STRING;

/**
 * Derives one package's sorted class map from its scan rules.
 *
 * @internal
 */
final class ClassMapScanner
{
    /**
     * Explicit classmap rules outrank PSR-4 directories, which outrank PSR-0 directories; a PSR rule claims only the
     * classes inside its namespace prefix. Files that cannot be read or parsed contribute no class.
     *
     * @param string $packageRoot Canonical package directory
     *
     * @throws GeneratorException When a candidate resolves outside the package root or cannot be scanned
     */
    public static function scan(string $packageRoot, ScanRuleSet $scanRules): ClassMap
    {
        $files = [];

        foreach ($scanRules->inclusions as $rule) {
            $candidate = $packageRoot . '/' . $rule->relativePath;

            switch ($rule->kind) {
                case ScanRuleKind::File:
                    CandidateFileCollector::collect($candidate, $packageRoot, $scanRules, $rule, $files);

                    break;

                case ScanRuleKind::Directory:
                    CandidateDirectoryCollector::collect($candidate, $packageRoot, $scanRules, $rule, $files);

                    break;

                case ScanRuleKind::Glob:
                    $matches = glob($candidate, GLOB_NOSORT);

                    if (false === $matches) {
                        throw new GeneratorException(sprintf('Unable to expand classmap pattern %s.', $candidate));
                    }

                    sort($matches, SORT_STRING);

                    foreach ($matches as $match) {
                        if (is_dir($match)) {
                            CandidateDirectoryCollector::collect($match, $packageRoot, $scanRules, $rule, $files);
                        } elseif (is_file($match)) {
                            CandidateFileCollector::collect($match, $packageRoot, $scanRules, $rule, $files);
                        }
                    }

                    break;
            }
        }

        $paths = array_keys($files);
        sort($paths, SORT_STRING);
        $selected = [];
        $attributeFiles = [];

        foreach ($paths as $file) {
            $source = @file_get_contents($file);
            $parsed = false === $source ? null : SourceParser::parse($source);

            if (null === $parsed) {
                continue;
            }

            foreach ($parsed->classNames as $className) {
                $priority = null;

                foreach ($files[$file] as $rule) {
                    if (ScanRuleSource::Classmap === $rule->source) {
                        $priority = 30;

                        continue;
                    }

                    $prefix = $rule->namespacePrefix ?? '';

                    if ('' !== $prefix && !str_starts_with($className, $prefix)) {
                        continue;
                    }

                    $candidatePriority = ScanRuleSource::Psr4 === $rule->source ? 20 : 10;

                    if (null === $priority || $candidatePriority > $priority) {
                        $priority = $candidatePriority;
                    }
                }

                if (null === $priority || (isset($selected[$className]) && $priority <= $selected[$className][1])) {
                    continue;
                }

                $selected[$className] = [
                    $file,
                    $priority,
                ];

                if ($parsed->hasAttributes) {
                    $attributeFiles[$file] = true;
                }
            }
        }

        $classes = [];

        foreach ($selected as $className => [$file]) {
            $classes[$className] = $file;
        }

        ksort($classes, SORT_STRING);

        return new ClassMap($classes, $attributeFiles);
    }
}
