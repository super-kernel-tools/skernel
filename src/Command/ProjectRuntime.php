<?php

declare(strict_types=1);

namespace Src\Command;

use Src\Composer\ComposerLocator;
use Src\Configuration\ConfigurationLoader;
use Src\Configuration\ProjectConfiguration;
use Src\Project\Project;
use Src\Project\ProjectLocator;
use Src\Toolchain\ManagedPhp;
use Src\Toolchain\Runtime;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides what a command that builds an installed project needs: the project from `--working-dir`, its configuration
 * file, its managed PHP, and the Composer that regenerates its autoloader.
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
     * Returns the project, its settings, its managed PHP, and the command that runs its Composer.
     *
     * @return array{Project, ProjectConfiguration, Runtime, non-empty-list<string>}
     *
     * @throws RuntimeException When the project or its configuration is invalid, or its managed PHP or Composer cannot
     *                          be provided
     */
    public function resolve(InputInterface $input, OutputInterface $errorOutput): array
    {
        $project = $this->projectLocator->locate($input->getOption('working-dir'));
        $configuration = $this->configurationLoader->load($project->directory);

        return [
            $project,
            $configuration,
            $this->managedPhp->runtime($project, $configuration->php, $errorOutput),
            $this->composerLocator->command($configuration->composer, $errorOutput),
        ];
    }
}
