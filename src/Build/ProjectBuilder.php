<?php

declare(strict_types=1);

namespace Src\Build;

use SKERNEL\Generator\Diagnostic\DiagnosticFormatter;
use Src\Process\PhpScript;
use Src\Project\Project;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;
use function var_export;

/**
 * Writes a project's build artifacts in separate PHP processes without creating or running the application.
 *
 * Composer first regenerates the project's autoloader, so that the build and the application see the classes added
 * since the project's dependencies were installed. The first PHP process then generates the package and annotation
 * records. The second bootstraps the project's container from them, without any class the first loaded, and publishes
 * `SuperKernel\Event\ApplicationBuild` so that components write the artifacts they prepare at build time, such as AOP
 * proxies. Projects without that event or a PSR-14 dispatcher have no listener for it, so the event is skipped.
 */
final readonly class ProjectBuilder
{
    private const string ENTRY = <<<'PHP'
        <?php

        declare(strict_types=1);

        require %s;

        $container = SuperKernel\Context\ApplicationContext::getContainer();

        if (
            class_exists(SuperKernel\Event\ApplicationBuild::class)
            && $container->has(Psr\EventDispatcher\EventDispatcherInterface::class)
        ) {
            $container->get(Psr\EventDispatcher\EventDispatcherInterface::class)
                ->dispatch(new SuperKernel\Event\ApplicationBuild());
        }

        PHP;

    public function __construct(private MetadataGenerator $metadataGenerator)
    {
    }

    /**
     * Runs the build in the project directory with the given PHP binary, a path or a name looked up in PATH, forwards
     * everything the processes write, and then lists the classes whose attributes could not be collected.
     *
     * @param non-empty-list<string> $composer       The command that runs Composer
     * @param string                 $skernelVersion The running SKERNEL's version, `UNKNOWN` or empty when it has none
     *
     * @throws RuntimeException When Composer cannot be started or fails, the PHP binary is not found or cannot be
     *                          started, the project's build protocol differs, or either PHP process fails
     */
    public function build(
        Project         $project,
        string          $phpBinary,
        array           $composer,
        string          $skernelVersion,
        OutputInterface $output,
    ): void
    {
        ComposerAutoloader::regenerate($project, $composer, $output);
        $diagnostics = $this->metadataGenerator->generate(
            $project,
            $phpBinary,
            '' === $skernelVersion || 'UNKNOWN' === $skernelVersion ? 'dev' : $skernelVersion,
            $output,
        );
        $entry = sprintf(self::ENTRY, var_export($project->vendorDirectory . '/autoload.php', true));
        $status = PhpScript::run($entry, $phpBinary, [], $project->directory, $output);

        if (0 !== $status) {
            throw new RuntimeException(sprintf('The project build failed with exit status %d.', $status));
        }

        foreach (DiagnosticFormatter::format($diagnostics) as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }
    }
}
