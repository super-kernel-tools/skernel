<?php

declare(strict_types=1);

namespace Src\Run;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Src\Project\Project;

use function clearstatcache;
use function filemtime;
use function filesize;
use function in_array;
use function ksort;
use function str_ends_with;
use function str_starts_with;

/**
 * Takes a snapshot of the project files whose changes restart a watched application: its PHP files, its `.env` and
 * `.env.*` files, and its `composer.lock`.
 *
 * The vendor directory, `target/`, and directories whose name starts with a dot are left out, as are other files, so
 * that the logs and caches a running application writes do not restart it.
 */
final class ProjectFiles
{
    /** @return array<string, array{int, int}> The modification time and size of each file by its path */
    public static function snapshot(Project $project): array
    {
        clearstatcache();
        $excluded = [
            $project->vendorDirectory,
            $project->directory . '/target',
        ];
        $directories = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($project->directory, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $file): bool => !$file->isDir() || (
                !str_starts_with($file->getFilename(), '.') && !in_array($file->getPathname(), $excluded, true)
            ),
        );
        $files = [];

        foreach (new RecursiveIteratorIterator(
            $directories,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        ) as $file) {
            $name = $file->getFilename();

            if (
                !str_ends_with($name, '.php')
                && 'composer.lock' !== $name
                && '.env' !== $name
                && !str_starts_with($name, '.env.')
            ) {
                continue;
            }

            // An editor may have replaced the file since it was listed, and a symbolic link may point nowhere.
            $modified = @filemtime($file->getPathname());
            $size = @filesize($file->getPathname());

            if (false !== $modified && false !== $size) {
                $files[$file->getPathname()] = [
                    $modified,
                    $size,
                ];
            }
        }

        ksort($files);

        return $files;
    }
}
