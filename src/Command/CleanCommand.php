<?php

declare(strict_types=1);

namespace Src\Command;

use Src\Filesystem\Directory;
use Src\Project\ProjectLocator;
use SuperKernel\Command\Attribute\Command as AttributeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function is_dir;
use function sprintf;

/**
 * Removes what `build` and `package` write by default: the `target/` directory and the scan caches.
 */
#[AttributeCommand(name: 'clean', description: "Remove the project's build output and scan caches")]
final class CleanCommand extends Command
{
    public function __construct(private readonly ProjectLocator $projectLocator)
    {
        parent::__construct();
    }

    /** @throws RuntimeException When the project is invalid or a directory cannot be removed */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->projectLocator->find($input->getOption('working-dir'));
        $removed = false;

        foreach ([
            $project->directory . '/target',
            $project->vendorDirectory . '/.cache',
        ] as $directory) {
            if (is_dir($directory)) {
                Directory::remove($directory);
                $output->writeln(sprintf('Removed %s.', $directory), OutputInterface::OUTPUT_RAW);
                $removed = true;
            }
        }

        if (!$removed) {
            $output->writeln('Nothing to clean.', OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }
}
