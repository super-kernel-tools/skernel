<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Attribute;

use ReflectionClass;
use SKERNEL\Generator\Diagnostic\Diagnostic;
use SKERNEL\Generator\Model\PackageRecord;
use Throwable;

/**
 * Collects the attributes of every package's classes, isolating each class's failure.
 *
 * @internal
 */
final class AttributeScanner
{
    /**
     * Loads only classes whose files contain an attribute. A class that throws while loading, reflecting, or
     * instantiating an attribute is skipped with a diagnostic, as is a class that ended an earlier run.
     *
     * @param list<PackageRecord>         $records
     * @param array<class-string, string> $fatalClasses Classes that ended an earlier run, with the fatal error message
     *
     * @return list<PackageAttributes> One per record, in record order
     */
    public static function scan(array $records, array $fatalClasses, ScanProgress $progress): array
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

                if ([] !== $attributes->class) {
                    $collected['classes'][$class] = $attributes->class;
                }

                if ([] !== $attributes->methods) {
                    $collected['methods'][$class] = $attributes->methods;
                }

                if ([] !== $attributes->properties) {
                    $collected['properties'][$class] = $attributes->properties;
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
