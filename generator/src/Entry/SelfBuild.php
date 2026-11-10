<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Entry;

use SKERNEL\Generator\Composer\VendorDirectory;
use SKERNEL\Generator\Generator;
use Throwable;

use function fwrite;
use function sprintf;

use const STDERR;

/**
 * Generates SKERNEL's own build artifacts from source, in its own process, before its container starts.
 *
 * @internal
 */
final class SelfBuild
{
    /**
     * Regenerates on every start, because the sources may have changed since the last one. Skipped classes stay
     * silent, because SKERNEL's own dependencies are not the developer's code; a failure ends the process with
     * status 1.
     *
     * @param string $root SKERNEL's project directory
     *
     * @return BuildLock Held until the caller has restored the artifacts, so that another start cannot invalidate them
     *                   in between
     */
    public static function run(string $root): BuildLock
    {
        try {
            $vendor = VendorDirectory::resolve($root);
            $lock = new BuildLock($vendor);
            Generator::generate($root, $vendor, null);

            return $lock;
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("SKERNEL cannot generate its own build artifacts: %s\n", $exception->getMessage()));

            exit(1);
        }
    }
}
