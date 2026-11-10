<?php

declare(strict_types=1);

namespace Src\Command;

use Symfony\Component\Console\Command\HelpCommand as ConsoleHelpCommand;

/**
 * Displays help for an SKERNEL command.
 */
final class HelpCommand extends ConsoleHelpCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->setDescription('Display help for an SKERNEL command');
    }
}
