<?php

declare(strict_types=1);

namespace Src\Command;

use Src\Build\ProjectBuilder;
use SuperKernel\Command\Attribute\Command as AttributeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * Generates the project's build artifacts with its managed PHP; the application loads them at start.
 */
#[AttributeCommand(name: 'build', description: 'Generate the build artifacts that the project loads at start')]
final class BuildCommand extends Command
{
    public function __construct(
        private readonly ProjectRuntime $projectRuntime,
        private readonly ProjectBuilder $projectBuilder,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'php',
            null,
            InputOption::VALUE_REQUIRED,
            'The PHP version that runs the project, such as 8.4 or 8.4.27',
        );
    }

    /**
     * @throws RuntimeException When the project or its configuration is invalid, its managed PHP cannot be provided,
     *                          the project's build protocol differs, or the build cannot start or fails
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        [$project, $runtime] = $this->projectRuntime->resolve($input, $errorOutput);
        $this->projectBuilder->build(
            $project,
            $runtime->php,
            (string) $this->getApplication()?->getVersion(),
            $errorOutput,
        );
        $output->writeln(
            sprintf('Build artifacts written to %s/.cache.', $project->vendorDirectory),
            OutputInterface::OUTPUT_RAW,
        );

        return self::SUCCESS;
    }
}
