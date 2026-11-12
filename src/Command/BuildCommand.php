<?php

declare(strict_types=1);

namespace Src\Command;

use Src\Build\ProjectBuilder;
use SuperKernel\Command\Attribute\Command as AttributeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * Regenerates the project's Composer autoloader and generates its build artifacts with its managed PHP; the
 * application loads them at start.
 *
 * The PHP version and the Composer come from the project's configuration file.
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

    /**
     * @throws RuntimeException When the project or its configuration is invalid, its managed PHP or Composer cannot be
     *                          provided, the project's build protocol differs, or the build cannot start or fails
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        [$project, , $runtime, $composer] = $this->projectRuntime->resolve($input, $errorOutput);
        $this->projectBuilder->build(
            $project,
            $runtime->php,
            $composer,
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
