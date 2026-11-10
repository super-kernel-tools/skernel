<?php

declare(strict_types=1);

namespace Src\Package;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Src\Filesystem\Directory;
use Symfony\Component\Console\Exception\RuntimeException;

use function array_map;
use function copy;
use function dirname;
use function in_array;
use function realpath;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Copies a project into a staging directory for packaging, following symbolic links.
 *
 * VCS metadata is skipped at every depth, the root's `.env` and `.env.*` files are skipped because they usually hold
 * machine-local secrets, and so is everything below the excluded paths.
 */
final class ProjectCopier
{
    private const array METADATA = [
        '.git',
        '.hg',
        '.svn',
    ];

    /**
     * @param list<string> $excludedPaths Absolute paths skipped with everything below them
     *
     * @throws RuntimeException When a directory or file cannot be copied
     */
    public static function copy(string $source, string $target, array $excludedPaths): void
    {
        $excluded = array_map(static fn(string $path): string => realpath($path) ?: $path, $excludedPaths);
        $entries = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(
                $source,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
            ),
            static fn(SplFileInfo $entry): bool => !in_array($entry->getFilename(), self::METADATA, true)
                && !in_array($entry->getPathname(), $excluded, true)
                && !(
                    dirname($entry->getPathname()) === $source
                    && ('.env' === $entry->getFilename() || str_starts_with($entry->getFilename(), '.env.'))
                ),
        ), RecursiveIteratorIterator::SELF_FIRST);
        Directory::create($target);

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $destination = $target . substr($entry->getPathname(), strlen($source));

            if ($entry->isDir()) {
                Directory::create($destination);
            } elseif (!@copy($entry->getPathname(), $destination)) {
                throw new RuntimeException(sprintf('Unable to copy %s into the packaged copy.', $entry->getPathname()));
            }
        }
    }
}
