<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Attribute;

/**
 * The attributes of one class and of the methods and properties it declares itself.
 *
 * @internal
 */
final readonly class ClassAttributes
{
    /**
     * @param list<CollectedAttribute>                          $class
     * @param array<non-empty-string, list<CollectedAttribute>> $methods    Non-empty lists keyed by method name
     * @param array<non-empty-string, list<CollectedAttribute>> $properties Non-empty lists keyed by property name
     */
    public function __construct(public array $class, public array $methods, public array $properties)
    {
    }
}
