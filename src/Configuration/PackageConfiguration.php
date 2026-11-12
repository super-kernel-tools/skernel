<?php

declare(strict_types=1);

namespace Src\Configuration;

/**
 * The `package` settings of a project's SKERNEL configuration file, with their defaults.
 */
final readonly class PackageConfiguration
{
    /**
     * @param string|null $runtime An absolute micro.sfx path, or null for the managed micro runtime
     * @param string|null $output  An absolute package path, or null for `target/<name>` or `target/<name>.phar`
     * @param bool        $dev     Whether the development dependencies are installed into the package
     * @param bool        $phar    Whether packaging stops at the PHAR instead of a standalone binary
     */
    public function __construct(
        public ?string $runtime = null,
        public ?string $output = null,
        public bool    $dev = false,
        public bool    $phar = false,
    )
    {
    }
}
