<?php

declare(strict_types=1);

namespace Src\Command;

use Src\Run\ProjectRunner;
use SuperKernel\Command\Attribute\Command as AttributeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds and runs the project from source with its managed PHP, and when the project's configuration file enables
 * watching, rebuilds and restarts it whenever a watched file changes.
 */
#[AttributeCommand(name: 'run', description: 'Build and run the project, and restart it on changes when it watches')]
final class RunCommand extends Command
{
    public function __construct(
        private readonly ProjectRuntime $projectRuntime,
        private readonly ProjectRunner  $projectRunner,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'arguments',
            InputArgument::IS_ARRAY,
            'The arguments of the application, such as swoole:start; put options after --',
        );
    }

    /**
     * @return int The exit status of the application
     *
     * @throws RuntimeException When the project or its configuration is invalid, its managed PHP or Composer cannot be
     *                          provided, or the application cannot be built or started
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        [$project, $configuration, $runtime, $composer] = $this->projectRuntime->resolve($input, $errorOutput);

        return $this->projectRunner->run(
            $project,
            $runtime->php,
            $composer,
            $input->getArgument('arguments'),
            $configuration->run,
            (string) $this->getApplication()?->getVersion(),
            $output,
            $errorOutput,
        );
    }
}
