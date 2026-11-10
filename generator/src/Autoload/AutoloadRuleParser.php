<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Autoload;

use SKERNEL\Generator\Constants\ScanRuleKind;
use SKERNEL\Generator\Constants\ScanRuleSource;
use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Path\FilesystemPath;
use stdClass;

use function array_is_list;
use function get_object_vars;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function property_exists;
use function realpath;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;

use const GLOB_NOSORT;

/**
 * Turns one Composer autoload object into the scan rules of a located package.
 *
 * @internal
 */
final class AutoloadRuleParser
{
    /**
     * Validates every declaration, keeping rules only for paths that exist; `files` autoloads are validated but name
     * no classes, so Composer's own autoloader keeps including them.
     *
     * @param string $packageRoot Canonical package directory
     *
     * @throws GeneratorException When a declaration is malformed, unsafe, or resolves outside the package
     */
    public static function parse(?stdClass $autoload, string $packageRoot): ScanRuleSet
    {
        if (null === $autoload) {
            return new ScanRuleSet();
        }

        $rules = [];
        $exclusions = [];

        foreach ([
            'psr-0' => ScanRuleSource::Psr0,
            'psr-4' => ScanRuleSource::Psr4,
        ] as $field => $source) {
            if (!property_exists($autoload, $field)) {
                continue;
            }

            $mappings = [] === $autoload->{$field} ? new stdClass() : $autoload->{$field};

            if (!$mappings instanceof stdClass) {
                throw new GeneratorException(sprintf('autoload.%s must be an object.', $field));
            }

            foreach (get_object_vars($mappings) as $prefix => $paths) {
                $prefix = (string) $prefix;

                if (str_contains($prefix, "\0")) {
                    throw new GeneratorException(sprintf('autoload.%s contains a null byte in a prefix.', $field));
                }

                if (ScanRuleSource::Psr4 === $source && '' !== $prefix && !str_ends_with($prefix, '\\')) {
                    throw new GeneratorException(sprintf(
                        'PSR-4 namespace prefix "%s" must end in a backslash.',
                        $prefix,
                    ));
                }

                $paths = is_string($paths)
                    ? [
                        $paths,
                    ]
                    : $paths;

                if (!is_array($paths) || !array_is_list($paths) || [] === $paths) {
                    throw new GeneratorException(sprintf(
                        'autoload.%s.%s must be a string or a non-empty string list.',
                        $field,
                        $prefix,
                    ));
                }

                foreach ($paths as $path) {
                    if (!is_string($path)) {
                        throw new GeneratorException(sprintf(
                            'autoload.%s.%s must contain only strings.',
                            $field,
                            $prefix,
                        ));
                    }

                    $relativePath = AutoloadPath::normalize($path);
                    $resolved = AutoloadPath::resolveExisting($packageRoot, $relativePath);

                    if (null !== $resolved && is_dir($resolved)) {
                        $rules[] = new ScanRule($relativePath, $source, ScanRuleKind::Directory, $prefix);
                    }
                }
            }
        }

        foreach (AutoloadStringList::read($autoload, 'classmap') as $path) {
            $relativePath = AutoloadPath::normalize($path, true);

            if (!str_contains($relativePath, '*')) {
                $resolved = AutoloadPath::resolveExisting($packageRoot, $relativePath);
                $kind = match (true) {
                    null === $resolved => null,
                    is_dir($resolved)  => ScanRuleKind::Directory,
                    is_file($resolved) => ScanRuleKind::File,
                    default            => null,
                };

                if (null !== $kind) {
                    $rules[] = new ScanRule($relativePath, ScanRuleSource::Classmap, $kind, null);
                }

                continue;
            }

            $matched = false;
            $matches = glob($packageRoot . '/' . $relativePath, GLOB_NOSORT);

            foreach (false === $matches ? [] : $matches as $match) {
                $resolvedMatch = realpath($match);

                if (false === $resolvedMatch || (!is_dir($resolvedMatch) && !is_file($resolvedMatch))) {
                    continue;
                }

                if (!FilesystemPath::isWithin(str_replace('\\', '/', $resolvedMatch), $packageRoot)) {
                    throw new GeneratorException(sprintf(
                        'Classmap glob "%s" resolves outside the package root.',
                        $relativePath,
                    ));
                }

                $matched = true;
            }

            if ($matched) {
                $rules[] = new ScanRule($relativePath, ScanRuleSource::Classmap, ScanRuleKind::Glob, null);
            }
        }

        foreach (AutoloadStringList::read($autoload, 'files') as $file) {
            AutoloadPath::normalize($file);
        }

        foreach (AutoloadStringList::read($autoload, 'exclude-from-classmap') as $pattern) {
            $exclusions[] = ExclusionPattern::normalize($pattern);
        }

        return new ScanRuleSet($rules, $exclusions);
    }
}
