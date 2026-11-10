<?php

declare(strict_types=1);

namespace Src\Project;

/**
 * A SuperKernel application directory and what its composer.json declares.
 */
final readonly class Project
{
    /**
     * @param string|null $name           The root package name from composer.json
     * @param string|null $phpRequirement The root package's Composer requirement on php
     */
    public function __construct(
        public string  $directory,
        public string  $vendorDirectory,
        public ?string $name,
        public ?string $phpRequirement,
    )
    {
    }
}
