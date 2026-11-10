<?php

declare(strict_types=1);

namespace Src\Command;

use Src\Run\ProjectRunner;
use SuperKernel\Command\Attribute\Command as AttributeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the project from source with its managed PHP, and with --watch restarts it whenever its files change.
 */
#[AttributeCommand(name: 'run', description: 'Run the project from source, and restart it on changes with --watch')]
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
        $this->addOption('watch', null, InputOption::VALUE_NONE, 'Restart the application when project files change');
        $this->addOption(
            'php',
            null,
            InputOption::VALUE_REQUIRED,
            'The PHP version that runs the project, such as 8.4 or 8.4.27',
        );
    }

    /**
     * @return int The exit status of the application
     *
     * @throws RuntimeException When the project or its configuration is invalid, its managed PHP cannot be provided,
     *                          or the application cannot start
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        [$project, $runtime] = $this->projectRuntime->resolve($input, $errorOutput);

        return $this->projectRunner->run(
            $project,
            $runtime->php,
            $input->getArgument('arguments'),
            $input->getOption('watch'),
            $output,
            $errorOutput,
        );
    }
}
