<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Attribute;

use Closure;
use ReflectionClass;
use SKERNEL\Generator\Diagnostic\Diagnostic;
use SKERNEL\Generator\Model\PackageRecord;
use Throwable;

use function array_map;

/**
 * Collects the attributes of every package's classes, isolating each class's failure.
 *
 * @internal
 */
final class AttributeScanner
{
    /**
     * Loads only classes whose files contain an attribute. A class that throws while loading, reflecting,
     * instantiating an attribute, or converting one is skipped with a diagnostic and contributes nothing, as is a
     * class that ended an earlier run.
     *
     * @param list<PackageRecord>                 $records
     * @param array<class-string, string>         $fatalClasses Classes that ended an earlier run, with the fatal
     *                                                          error message
     * @param Closure(CollectedAttribute): object $convert      Builds the published model of one attribute, such as
     *                                                          the annotator's, and throws when it cannot
     *
     * @return list<PackageAttributes> One per record, in record order
     */
    public static function scan(array $records, array $fatalClasses, ScanProgress $progress, Closure $convert): array
    {
        $sequence = 0;
        $packages = [];

        foreach ($records as $record) {
            $package = $record->declaration->name;
            $collected = [
                'classes'    => [],
                'methods'    => [],
                'properties' => [],
            ];
            $diagnostics = [];

            foreach ($record->classMap->classes as $class => $file) {
                if (isset($fatalClasses[$class])) {
                    $diagnostics[] = new Diagnostic(
                        ++$sequence,
                        $package,
                        $class,
                        'fatal error',
                        $fatalClasses[$class],
                    );

                    continue;
                }

                if (!isset($record->classMap->attributeFiles[$file])) {
                    continue;
                }

                $progress->package = $package;
                $progress->class = $class;

                try {
                    $attributes = ClassAttributeCollector::collect(new ReflectionClass($class));
                    $converted = [
                        'classes'    => array_map($convert, $attributes->class),
                        'methods'    => [],
                        'properties' => [],
                    ];

                    foreach ([
                        'methods'    => $attributes->methods,
                        'properties' => $attributes->properties,
                    ] as $kind => $members) {
                        foreach ($members as $member => $list) {
                            $converted[$kind][$member] = array_map($convert, $list);
                        }
                    }
                } catch (Throwable $exception) {
                    $diagnostics[] = new Diagnostic(
                        ++$sequence,
                        $package,
                        $class,
                        $exception::class,
                        $exception->getMessage(),
                    );

                    continue;
                } finally {
                    $progress->package = null;
                    $progress->class = null;
                }

                foreach ($converted as $kind => $value) {
                    if ([] !== $value) {
                        $collected[$kind][$class] = $value;
                    }
                }
            }

            $packages[] = new PackageAttributes(
                $package,
                $record->declaration->reference,
                $collected['classes'],
                $collected['methods'],
                $collected['properties'],
                $diagnostics,
            );
        }

        return $packages;
    }
}
