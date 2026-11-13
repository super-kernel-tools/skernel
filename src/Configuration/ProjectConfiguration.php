<?php

declare(strict_types=1);

namespace Src\Configuration;

/**
 * The settings of a project's SKERNEL configuration file, with their defaults.
 */
final readonly class ProjectConfiguration
{
    /**
     * @param string|null $php      A PHP version, or null for the latest one that the root package's `require.php`
     *                              allows
     * @param bool        $prebuilt Whether a missing managed PHP may be downloaded prebuilt instead of being built
     *                              on this machine
     * @param string|null $composer An absolute Composer path or a name looked up in PATH, or null for the first
     *                              working Composer 2 or else SKERNEL's own
     */
    public function __construct(
        public ?string              $php = null,
        public bool                 $prebuilt = true,
        public ?string              $composer = null,
        public RunConfiguration     $run = new RunConfiguration(),
        public PackageConfiguration $package = new PackageConfiguration(),
    )
    {
    }
}
