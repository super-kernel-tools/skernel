<?php

declare(strict_types=1);

namespace Src\Configuration;

/**
 * The `package` settings of a project's SKERNEL configuration file; null leaves a setting to its option or default.
 */
final readonly class PackageConfiguration
{
    public function __construct(
        public ?string $runtime = null,
        public ?string $output = null,
        public ?string $composer = null,
        public ?bool   $dev = null,
        public ?bool   $phar = null,
    )
    {
    }
}
