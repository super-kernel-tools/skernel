<?php

declare(strict_types=1);

namespace Src\Build;

use Src\Process\ProcessRunner;
use Src\Project\JsonFile;
use Src\Project\Project;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

use function is_array;
use function is_bool;
use function sprintf;

/**
 * Regenerates a project's Composer autoloader, so that it knows the classes added since its dependencies were
 * installed.
 *
 * Composer takes the optimization and authoritative settings from the project's composer.json. The development
 * autoload rules follow the installation that `<vendor>/composer/installed.json` records, so that a project installed
 * without development dependencies does not get them back. Composer runs in its own process, as do the project's
 * scripts that it runs.
 */
final class ComposerAutoloader
{
    /**
     * @param non-empty-list<string> $composer The command that runs Composer
     *
     * @throws RuntimeException When the installed state cannot be read, or Composer cannot be started or fails
     */
    public static function regenerate(Project $project, array $composer, OutputInterface $output): void
    {
        $installed = JsonFile::decode($project->vendorDirectory . '/composer/installed.json');
        $dev = is_array($installed) ? $installed['dev'] ?? null : null;
        $command = [
            ...$composer,
            'dump-autoload',
            '--no-interaction',
        ];

        if (is_bool($dev)) {
            $command[] = $dev ? '--dev' : '--no-dev';
        }

        $status = ProcessRunner::run(
            $command,
            $project->directory,
            $output,
            sprintf('the Composer binary %s', $composer[0]),
        );

        if (0 !== $status) {
            throw new RuntimeException(sprintf(
                'Regenerating the Composer autoloader failed with exit status %d.',
                $status,
            ));
        }
    }
}
