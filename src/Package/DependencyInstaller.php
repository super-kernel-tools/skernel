<?php

declare(strict_types=1);

namespace Src\Package;

use Src\Process\ProcessRunner;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * Installs a packaged copy's dependencies from its composer.lock.
 *
 * Platform requirements are not checked against the PHP that runs Composer, because the managed runtime is the
 * packaged application's platform; the build then loads Composer's platform check with that runtime.
 */
final readonly class DependencyInstaller
{
    /**
     * @param non-empty-list<string> $composer The command that runs Composer
     * @param bool                   $dev      Whether development dependencies are installed too
     *
     * @return int The exit status of Composer
     *
     * @throws RuntimeException When Composer cannot be started
     */
    public function install(string $directory, array $composer, bool $dev, OutputInterface $output): int
    {
        $command = [
            ...$composer,
            'install',
            '--no-interaction',
            '--no-progress',
            '--ignore-platform-reqs',
        ];

        if (!$dev) {
            $command[] = '--no-dev';
        }

        return ProcessRunner::run($command, $directory, $output, sprintf('the Composer binary %s', $composer[0]), [
            'COMPOSER_MIRROR_PATH_REPOS' => '1',
        ]);
    }
}
