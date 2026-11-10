<?php

declare(strict_types=1);

namespace Src\Package;

use Src\Process\PhpScript;
use Src\Project\Project;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

use function basename;
use function sprintf;
use function strlen;
use function substr;
use function var_export;

/**
 * Archives a built project into a PHAR whose stub runs the standard application entry sequence.
 *
 * The archive is written by a separate PHP process with phar.readonly disabled, because that setting cannot be
 * changed at runtime.
 */
final readonly class PharBuilder
{
    private const string ENTRY = <<<'PHP'
        <?php

        declare(strict_types=1);

        $phar = new Phar(%s);
        $phar->startBuffering();
        $phar->buildFromDirectory(%s);
        $phar->setStub(%s);
        $phar->stopBuffering();

        PHP;

    private const string STUB = <<<'PHP'
        %s<?php

        declare(strict_types=1);

        Phar::mapPhar(%s);

        require %s;

        SuperKernel\Context\ApplicationContext::getContainer()
            ->get(SuperKernel\Contract\ApplicationInterface::class)
            ->run();

        __HALT_COMPILER();

        PHP;

    /**
     * @param string $phar       The archive path, ending in .phar; its file name becomes the archive alias
     * @param bool   $executable Whether the stub starts with a shebang line so that the PHAR runs directly
     *
     * @return int The exit status of the PHP process
     *
     * @throws RuntimeException When the vendor directory lies outside the project, or the PHP process cannot start
     */
    public function build(
        Project         $project,
        string          $phar,
        bool            $executable,
        string          $phpBinary,
        OutputInterface $output,
    ): int
    {
        $vendor = substr($project->vendorDirectory, strlen($project->directory) + 1);

        if ($project->directory . '/' . $vendor !== $project->vendorDirectory) {
            throw new RuntimeException(sprintf(
                'The vendor directory %s must lie inside the project to package it.',
                $project->vendorDirectory,
            ));
        }

        $alias = basename($phar);
        $stub = sprintf(
            self::STUB,
            $executable ? "#!/usr/bin/env php\n" : '',
            var_export($alias, true),
            var_export('phar://' . $alias . '/' . $vendor . '/autoload.php', true),
        );
        $entry = sprintf(
            self::ENTRY,
            var_export($phar, true),
            var_export($project->directory, true),
            var_export($stub, true),
        );

        return PhpScript::run($entry, $phpBinary, [
            'phar.readonly' => '0',
        ], $project->directory, $output);
    }
}
