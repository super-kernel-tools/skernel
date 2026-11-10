<?php

declare(strict_types=1);

namespace SKERNEL\Generator\ClassMap;

/**
 * The classes of one package and which of their files use native attributes.
 *
 * @internal
 */
final readonly class ClassMap
{
    /**
     * @param array<class-string, non-empty-string> $classes        Canonical absolute files, sorted by class
     * @param array<non-empty-string, true>         $attributeFiles Files that contain at least one attribute
     */
    public function __construct(public array $classes = [], public array $attributeFiles = [])
    {
    }
}
