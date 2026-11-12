<?php

declare(strict_types=1);

namespace Src\Run;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Src\Project\Project;

use function array_filter;
use function clearstatcache;
use function filemtime;
use function filesize;
use function fnmatch;
use function in_array;
use function ksort;
use function str_starts_with;

/**
 * Takes a snapshot of the project files whose changes restart a watched application: the files whose name matches one
 * of the watched patterns, with `*`, `?`, and `[...]`.
 *
 * The vendor directory, `target/`, and directories whose name starts with a dot are left out, as are other files, so
 * that the logs and caches a running application writes do not restart it.
 */
final class ProjectFiles
{
    /**
     * @param non-empty-list<string> $patterns The name patterns of the watched files
     *
     * @return array<string, array{int, int}> The modification time and size of each file by its path
     */
    public static function snapshot(Project $project, array $patterns): array
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

            if ([] === array_filter($patterns, static fn(string $pattern): bool => fnmatch($pattern, $name))) {
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
