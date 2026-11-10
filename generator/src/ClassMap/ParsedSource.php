<?php

declare(strict_types=1);

namespace SKERNEL\Generator\ClassMap;

/**
 * The named class-like declarations of one PHP source file and whether it uses native attributes.
 *
 * @internal
 */
final readonly class ParsedSource
{
    /**
     * @param list<class-string> $classNames Sorted, unique, and non-empty
     */
    public function __construct(public array $classNames, public bool $hasAttributes)
    {
    }
}
