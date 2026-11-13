<?php

declare(strict_types=1);

namespace Src\Command;

use Src\Build\ProjectBuilder;
use Src\Composer\ComposerLocator;
use Src\Configuration\ConfigurationLoader;
use Src\Filesystem\Directory;
use Src\Package\DependencyInstaller;
use Src\Package\PackageFile;
use Src\Package\PharBuilder;
use Src\Package\ProjectCopier;
use Src\Project\ProjectLocator;
use Src\Toolchain\ManagedPhp;
use SuperKernel\Command\Attribute\Command as AttributeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function basename;
use function is_file;
use function sprintf;
use function strrchr;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Builds a staged copy of the project with its managed PHP and packages it as a standalone binary or a PHAR.
 *
 * The PHP version, the Composer, and what is packaged where come from the project's configuration file.
 */
#[AttributeCommand(name: 'package', description: 'Package the project as a standalone binary or a PHAR')]
final class PackageCommand extends Command
{
    public function __construct(
        private readonly ProjectLocator      $projectLocator,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly ComposerLocator     $composerLocator,
        private readonly DependencyInstaller $dependencyInstaller,
        private readonly ManagedPhp          $managedPhp,
        private readonly ProjectBuilder      $projectBuilder,
        private readonly PharBuilder         $pharBuilder,
    )
    {
        parent::__construct();
    }

    /**
     * @throws RuntimeException When the project or its configuration is invalid, or staging, installing, building,
     *                          or writing the package fails
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->projectLocator->find($input->getOption('working-dir'));
        $settings = $this->configurationLoader->load($project->directory);
        $configuration = $settings->package;

        if (!is_file($project->directory . '/composer.lock')) {
            throw new RuntimeException(sprintf(
                'The project in %s has no composer.lock; run composer install first.',
                $project->directory,
            ));
        }

        $phar = $configuration->phar;
        $runtime = $configuration->runtime;

        if (!$phar && null !== $runtime && !is_file($runtime)) {
            throw new RuntimeException(sprintf('The micro runtime %s was not found.', $runtime));
        }

        $name = null === $project->name
            ? basename($project->directory)
            : substr((string) strrchr('/' . $project->name, '/'), 1);
        $target = $configuration->output ?? $project->directory . '/target/' . $name . ($phar ? '.phar' : '');
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $staging = tempnam(sys_get_temp_dir(), 'skernel-package-');

        if (false === $staging || !unlink($staging)) {
            throw new RuntimeException('Unable to create a staging directory for the package.');
        }

        try {
            // The configuration file holds SKERNEL's settings for this machine's builds, which the package never reads.
            ProjectCopier::copy($project->directory, $staging . '/project', [
                $project->vendorDirectory,
                $project->directory . '/target',
                $project->directory . '/skernel.json',
                $project->directory . '/skernel.json.dist',
                $target,
            ]);
            $composer = $this->composerLocator->command($settings->composer, $settings->prebuilt, $errorOutput);
            $status = $this->dependencyInstaller->install(
                $staging . '/project',
                $composer,
                $configuration->dev,
                $errorOutput,
            );

            if (0 !== $status) {
                throw new RuntimeException(sprintf(
                    'Installing the dependencies of the packaged copy failed with exit status %d.',
                    $status,
                ));
            }

            $staged = $this->projectLocator->locate($staging . '/project');
            $managed = $this->managedPhp->runtime($staged, $settings->php, $settings->prebuilt, $errorOutput);
            $this->projectBuilder->build(
                $staged,
                $managed->php,
                $composer,
                (string) $this->getApplication()?->getVersion(),
                $errorOutput,
            );
            $archive = $staging . '/' . $name . '.phar';
            $status = $this->pharBuilder->build($staged, $archive, $phar, $managed->php, $errorOutput);

            if (0 !== $status) {
                throw new RuntimeException(sprintf('Assembling the PHAR failed with exit status %d.', $status));
            }

            PackageFile::write($target, $phar ? [
                $archive,
            ] : [
                $runtime ?? $managed->micro,
                $archive,
            ]);
        } finally {
            Directory::remove($staging);
        }

        $output->writeln(sprintf('Package written to %s.', $target), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
