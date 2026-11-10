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
use Src\Project\AbsolutePath;
use Src\Project\ProjectLocator;
use Src\Toolchain\ManagedPhp;
use Src\Toolchain\PhpVersion;
use SuperKernel\Command\Attribute\Command as AttributeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function basename;
use function getcwd;
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
 * Options left off the command line come from the project's SKERNEL configuration file, then from the defaults.
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

    protected function configure(): void
    {
        $this->addOption('phar', null, InputOption::VALUE_NONE, 'Stop at the PHAR instead of a standalone binary');
        $this->addOption('dev', null, InputOption::VALUE_NONE, 'Install the development dependencies into the package');
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'The package path, by default target/<name>');
        $this->addOption(
            'php',
            null,
            InputOption::VALUE_REQUIRED,
            'The PHP version that runs the package, such as 8.4 or 8.4.27',
        );
        $this->addOption(
            'composer',
            null,
            InputOption::VALUE_REQUIRED,
            "The Composer binary that installs the packaged copy, by default a working Composer 2 or else SKERNEL's own",
        );
        $this->addOption(
            'runtime',
            null,
            InputOption::VALUE_REQUIRED,
            'A micro.sfx that replaces the managed micro runtime of the standalone binary',
        );
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
        $workingDirectory = (string) getcwd();
        $php = $input->getOption('php');

        if (null !== $php && !PhpVersion::isValid($php)) {
            throw new RuntimeException('The --php option must be a PHP version such as 8.4 or 8.4.27.');
        }

        if (!is_file($project->directory . '/composer.lock')) {
            throw new RuntimeException(sprintf(
                'The project in %s has no composer.lock; run composer install first.',
                $project->directory,
            ));
        }

        $phar = $input->getOption('phar') || true === $configuration->phar;
        $runtime = $input->getOption('runtime');
        $runtime = null === $runtime ? $configuration->runtime : AbsolutePath::resolve($runtime, $workingDirectory);

        if (!$phar && null !== $runtime && !is_file($runtime)) {
            throw new RuntimeException(sprintf('The micro runtime %s was not found.', $runtime));
        }

        $name = null === $project->name
            ? basename($project->directory)
            : substr((string) strrchr('/' . $project->name, '/'), 1);
        $target = $input->getOption('output');
        $target = null === $target ? $configuration->output : AbsolutePath::resolve($target, $workingDirectory);
        $target ??= $project->directory . '/target/' . $name . ($phar ? '.phar' : '');
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $staging = tempnam(sys_get_temp_dir(), 'skernel-package-');

        if (false === $staging || !unlink($staging)) {
            throw new RuntimeException('Unable to create a staging directory for the package.');
        }

        try {
            ProjectCopier::copy($project->directory, $staging . '/project', [
                $project->vendorDirectory,
                $project->directory . '/target',
                $target,
            ]);
            $status = $this->dependencyInstaller->install(
                $staging . '/project',
                $this->composerLocator->command(
                    $input->getOption('composer') ?? $configuration->composer,
                    $errorOutput,
                ),
                $input->getOption('dev') || true === $configuration->dev,
                $errorOutput,
            );

            if (0 !== $status) {
                throw new RuntimeException(sprintf(
                    'Installing the dependencies of the packaged copy failed with exit status %d.',
                    $status,
                ));
            }

            $staged = $this->projectLocator->locate($staging . '/project');
            $managed = $this->managedPhp->runtime(
                $staged,
                $php ?? $settings->php,
                $errorOutput,
            );
            $status = $this->projectBuilder->build($staged, $managed->php, $errorOutput);

            if (0 !== $status) {
                throw new RuntimeException(sprintf('The project build failed with exit status %d.', $status));
            }

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
