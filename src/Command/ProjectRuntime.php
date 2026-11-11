<?php

declare(strict_types=1);

namespace Src\Command;

use Src\Composer\ComposerLocator;
use Src\Configuration\ConfigurationLoader;
use Src\Project\Project;
use Src\Project\ProjectLocator;
use Src\Toolchain\ManagedPhp;
use Src\Toolchain\PhpVersion;
use Src\Toolchain\Runtime;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides what a command that builds an installed project needs: the project from `--working-dir`, its managed PHP
 * from `--php` or the project's configuration file, and the Composer that regenerates its autoloader.
 */
final readonly class ProjectRuntime
{
    public function __construct(
        private ProjectLocator      $projectLocator,
        private ConfigurationLoader $configurationLoader,
        private ManagedPhp          $managedPhp,
        private ComposerLocator     $composerLocator,
    )
    {
    }

    /**
     * @return array{Project, Runtime, non-empty-list<string>} The project, its managed PHP, and the Composer command
     *
     * @throws RuntimeException When the project or its configuration is invalid, or its managed PHP or Composer cannot
     *                          be provided
     */
    public function resolve(InputInterface $input, OutputInterface $errorOutput): array
    {
        $project = $this->projectLocator->locate($input->getOption('working-dir'));
        $php = $input->getOption('php');

        if (null !== $php && !PhpVersion::isValid($php)) {
            throw new RuntimeException('The --php option must be a PHP version such as 8.4 or 8.4.27.');
        }

        return [
            $project,
            $this->managedPhp->runtime(
                $project,
                $php ?? $this->configurationLoader->load($project->directory)->php,
                $errorOutput,
            ),
            $this->composerLocator->command(null, $errorOutput),
        ];
    }
}
