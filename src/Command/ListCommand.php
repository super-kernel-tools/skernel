<?php

declare(strict_types=1);

namespace Src\Command;

use Symfony\Component\Console\Command\ListCommand as ConsoleListCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists SKERNEL commands and opens the plain-text application overview with the SKERNEL logo.
 */
final class ListCommand extends ConsoleListCommand
{
    private const string LOGO = <<<'LOGO'
           _____ __ __ __________  _   __________
          / ___// //_// ____/ __ \/ | / / ____/ /
          \__ \/ ,<  / __/ / /_/ /  |/ / __/ / /
         ___/ / /| |/ /___/ _, _/ /|  / /___/ /___
        /____/_/ |_/_____/_/ |_/_/ |_/_____/_____/
        LOGO;

    protected function configure(): void
    {
        parent::configure();

        $this->setDescription('List SKERNEL commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('txt' === $input->getOption('format')
            && !$input->getOption('raw')
            && null === $input->getArgument('namespace')) {
            $output->writeln(self::LOGO, OutputInterface::OUTPUT_RAW);
        }

        return parent::execute($input, $output);
    }
}
