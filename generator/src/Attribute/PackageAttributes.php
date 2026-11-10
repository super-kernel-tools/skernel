<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Attribute;

use SKERNEL\Generator\Diagnostic\Diagnostic;

/**
 * The attributes collected from one package's classes, and the classes skipped.
 *
 * @internal
 */
final readonly class PackageAttributes
{
    /**
     * @param array<class-string, list<CollectedAttribute>>                          $classes
     * @param array<class-string, array<non-empty-string, list<CollectedAttribute>>> $methods
     * @param array<class-string, array<non-empty-string, list<CollectedAttribute>>> $properties
     * @param list<Diagnostic>                                                       $diagnostics
     */
    public function __construct(
        public string  $package,
        public ?string $reference,
        public array   $classes,
        public array   $methods,
        public array   $properties,
        public array   $diagnostics,
    )
    {
    }
}
