<?php

declare(strict_types=1);

namespace Src\Filesystem;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Exception\RuntimeException;

use function is_dir;
use function is_link;
use function mkdir;
use function rmdir;
use function sprintf;
use function unlink;

/**
 * Creates and removes directories that SKERNEL owns, such as staging copies and build jobs.
 */
final class Directory
{
    /** @throws RuntimeException When the directory does not exist and cannot be created */
    public static function create(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the directory %s.', $directory));
        }
    }

    /**
     * Removes the directory and everything below it without following symbolic links.
     *
     * @throws RuntimeException When an entry cannot be removed
     */
    public static function remove(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $path = $entry->getPathname();

            if ($entry->isDir() && !$entry->isLink() ? !@rmdir($path) : !@unlink($path)) {
                throw new RuntimeException(sprintf('Unable to remove %s.', $path));
            }
        }

        if (!@rmdir($directory)) {
            throw new RuntimeException(sprintf('Unable to remove %s.', $directory));
        }
    }
}
