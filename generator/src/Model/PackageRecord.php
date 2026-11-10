<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Model;

use SKERNEL\Generator\ClassMap\ClassMap;

/**
 * An installed package with its location and class map, ready to publish.
 *
 * @internal
 */
final readonly class PackageRecord
{
    /**
     * @param string|null $locatedPath Canonical absolute directory; null for a package without files, such as a
     *                                 metapackage
     */
    public function __construct(
        public PackageDeclaration $declaration,
        public ?string            $locatedPath,
        public ClassMap           $classMap,
    )
    {
    }
}
