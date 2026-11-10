<?php

declare(strict_types=1);

namespace Src\Configuration;

/**
 * The settings of a project's SKERNEL configuration file; null leaves a setting to its option or default.
 */
final readonly class ProjectConfiguration
{
    public function __construct(
        public ?string              $php = null,
        public PackageConfiguration $package = new PackageConfiguration(),
    )
    {
    }
}
