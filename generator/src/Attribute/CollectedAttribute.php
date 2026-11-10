<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Attribute;

/**
 * One instantiated attribute with the types it can be looked up by.
 *
 * @internal
 */
final readonly class CollectedAttribute
{
    /**
     * @param list<class-string> $types The attribute's class first, then its parent classes, then its interfaces
     */
    public function __construct(public object $instance, public array $types)
    {
    }
}
