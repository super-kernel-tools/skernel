<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Entry;

use SKERNEL\Generator\Exception\GeneratorException;

use function fclose;
use function flock;
use function fopen;
use function is_dir;
use function mkdir;
use function sprintf;

use const LOCK_EX;
use const LOCK_UN;

/**
 * An exclusive lock on `.cache/skernel.lock` below a vendor directory, which serializes SKERNEL starts from the same
 * source tree between generating the build artifacts and restoring them.
 *
 * @internal
 */
final class BuildLock
{
    /** @var resource|null */
    private mixed $handle;

    /**
     * Waits until no other start holds the lock.
     *
     * @throws GeneratorException When the lock file cannot be created or locked
     */
    public function __construct(string $vendorDirectory)
    {
        $directory = $vendorDirectory . '/.cache';

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new GeneratorException(sprintf('Unable to create the directory %s.', $directory));
        }

        $handle = @fopen($directory . '/skernel.lock', 'c');

        if (false === $handle || !flock($handle, LOCK_EX)) {
            throw new GeneratorException(sprintf('Unable to lock %s/skernel.lock.', $directory));
        }

        $this->handle = $handle;
    }

    /**
     * Releases the lock; releasing it again does nothing, and the process's exit releases it too.
     */
    public function release(): void
    {
        if (null === $this->handle) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
