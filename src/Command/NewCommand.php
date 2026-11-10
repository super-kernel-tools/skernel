<?php

declare(strict_types=1);

namespace Src\Command;

use FilesystemIterator;
use Src\Composer\ComposerLocator;
use Src\Process\ProcessRunner;
use Src\Project\AbsolutePath;
use SuperKernel\Command\Attribute\Command as AttributeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function file_exists;
use function getcwd;
use function is_dir;
use function iterator_count;
use function sprintf;

/**
 * Creates a project from the SuperKernel skeleton with Composer.
 *
 * Platform requirements are not checked against the PHP that runs Composer, because the project runs on its managed
 * PHP, which `build`, `run`, and `package` build later.
 */
#[AttributeCommand(name: 'new', description: 'Create a project from the SuperKernel skeleton')]
final class NewCommand extends Command
{
    private const string SKELETON = 'super-kernel/skeleton';

    public function __construct(private readonly ComposerLocator $composerLocator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('directory', InputArgument::REQUIRED, 'The directory of the new project');
        $this->addOption(
            'composer',
            null,
            InputOption::VALUE_REQUIRED,
            "The Composer binary that creates the project, by default a working Composer 2 or else SKERNEL's own",
        );
    }

    /**
     * @throws RuntimeException When the directory is in use, Composer cannot be provided, or it fails
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $current = (string) getcwd();
        $base = AbsolutePath::resolve($input->getOption('working-dir') ?? $current, $current);
        $directory = AbsolutePath::resolve($input->getArgument('directory'), $base);

        if (
            file_exists($directory)
            && (!is_dir($directory) || 0 < iterator_count(new FilesystemIterator($directory)))
        ) {
            throw new RuntimeException(sprintf('The directory %s already exists and is not empty.', $directory));
        }

        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $status = ProcessRunner::run([
            ...$this->composerLocator->command($input->getOption('composer'), $errorOutput),
            'create-project',
            self::SKELETON,
            $directory,
            '--stability=dev',
            '--no-interaction',
            '--no-progress',
            '--ignore-platform-reqs',
        ], $current, $errorOutput, 'Composer');

        if (0 !== $status) {
            throw new RuntimeException(sprintf('Creating the project failed with exit status %d.', $status));
        }

        $output->writeln(sprintf('Project created in %s.', $directory), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
