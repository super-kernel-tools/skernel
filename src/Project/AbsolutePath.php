<?php

declare(strict_types=1);

namespace Src\Project;

use function preg_match;
use function rtrim;

/**
 * Resolves a path given in a configuration file or on the command line against its base directory.
 */
final class AbsolutePath
{
    public static function resolve(string $path, string $baseDirectory): string
    {
        if (1 === preg_match('#\A(?:[a-zA-Z]:)?[/\\\\]#', $path)) {
            return $path;
        }

        return rtrim($baseDirectory, '/\\') . '/' . $path;
    }
}
