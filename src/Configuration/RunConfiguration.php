<?php

declare(strict_types=1);

namespace Src\Configuration;

/**
 * The `run` settings of a project's SKERNEL configuration file, with their defaults.
 */
final readonly class RunConfiguration
{
    /**
     * @param bool                   $watch      Whether `run` restarts the application when a watched file changes
     * @param non-empty-list<string> $watchFiles The name patterns of the watched files, with `*`, `?`, and `[...]`
     */
    public function __construct(
        public bool  $watch = false,
        public array $watchFiles = [
            '*.php',
            '.env',
            '.env.*',
            'composer.json',
            'composer.lock',
        ],
    )
    {
    }
}
