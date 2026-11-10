<?php

declare(strict_types=1);

namespace Src\Listener;

use Src\Command\HelpCommand;
use Src\Command\ListCommand;
use SuperKernel\Attribute\Listener;
use SuperKernel\Contract\ListenerInterface;
use SuperKernel\Event\BeforeApplicationStart;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;

/**
 * Presents the console as SKERNEL, adds the global project-directory option, and replaces Symfony's help and list
 * commands with SKERNEL's own.
 */
#[Listener]
final readonly class BeforeApplicationStartListener implements ListenerInterface
{
    public function __construct(private HelpCommand $helpCommand, private ListCommand $listCommand)
    {
    }

    public function listen(): array
    {
        return [
            BeforeApplicationStart::class,
        ];
    }

    /**
     * @psalm-param BeforeApplicationStart $event
     */
    public function process(object $event): void
    {
        $application = $event->application();

        if ($application instanceof Application) {
            $application->setName('SKERNEL');
            $application->getDefinition()->addOption(new InputOption(
                'working-dir',
                'd',
                InputOption::VALUE_REQUIRED,
                'Use the given directory as the project directory instead of the current one',
            ));
            $application->addCommand($this->helpCommand);
            $application->addCommand($this->listCommand);
        }
    }
}
