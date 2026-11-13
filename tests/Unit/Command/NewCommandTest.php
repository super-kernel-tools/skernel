<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Command;

use Exception;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Src\Command\NewCommand;
use Src\Composer\ComposerLocator;
use Src\Composer\ManagedComposer;
use Src\Tests\Unit\Toolchain\ToolchainTestCase;
use Src\Toolchain\DailyCache;
use Src\Toolchain\ExtensionVersions;
use Src\Toolchain\Home;
use Src\Toolchain\PhpReleases;
use Src\Toolchain\ReleaseIndex;
use Src\Toolchain\RuntimeProvider;
use Src\Toolchain\StaticPhpCli;
use Src\Toolchain\ZigMirrors;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\ApplicationTester;

use function escapeshellarg;

/**
 * Runs `new` in an application with and without a version, with a stand-in Composer that records its arguments.
 */
#[RequiresOperatingSystemFamily('Linux')]
final class NewCommandTest extends ToolchainTestCase
{
    /** @throws Exception If Console command initialization or execution fails */
    public function testCreatesTheSkeletonAtTheVersionOfAReleasedSkernel(): void
    {
        $this->composer('');

        self::assertSame(Command::SUCCESS, $this->new('1.0.0-alpha.3'));
        self::assertStringEqualsFile(
            $this->home . '/arguments',
            'create-project super-kernel/skeleton ' . $this->home . '/my-app 1.0.0-alpha.3 --no-interaction'
            . " --no-progress --ignore-platform-reqs\n",
        );
    }

    /** @throws Exception If Console command initialization or execution fails */
    public function testCreatesTheSkeletonFromTheDevelopmentLineWithoutAVersion(): void
    {
        $this->composer('');

        self::assertSame(Command::SUCCESS, $this->new(null));
        self::assertStringEqualsFile(
            $this->home . '/arguments',
            'create-project super-kernel/skeleton ' . $this->home . '/my-app --stability=dev --no-interaction'
            . " --no-progress --ignore-platform-reqs\n",
        );
    }

    /** @throws Exception If Console command initialization or execution fails */
    public function testSaysWhatToDoWhenTheSkeletonOfItsVersionDoesNotExist(): void
    {
        $this->composer('exit 1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'Creating the project failed with exit status 1. This SKERNEL creates super-kernel/skeleton 1.0.0-alpha.3;'
            . ' if Composer could not find that version, run skernel self-update and create the project again.',
        );

        $this->new('1.0.0-alpha.3');
    }

    /** @throws Exception If Console command initialization or execution fails */
    public function testReportsAFailedComposerWithoutAVersion(): void
    {
        $this->composer('exit 1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Creating the project failed with exit status 1.');

        $this->new(null);
    }

    /** Writes a stand-in Composer that records its arguments and then runs the given shell code */
    private function composer(string $code): void
    {
        $this->executable(
            $this->home . '/bin/composer',
            'echo "$*" > ' . escapeshellarg($this->home . '/arguments') . "\n" . $code,
        );
    }

    /**
     * Runs `new my-app` in the temporary home with the stand-in Composer, in an application with the given version.
     *
     * @return int The exit status
     *
     * @throws Exception If Console command initialization or execution fails
     */
    private function new(?string $version): int
    {
        $application = null === $version ? new Application('SKERNEL') : new Application('SKERNEL', $version);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->getDefinition()->addOption(new InputOption('working-dir', 'd', InputOption::VALUE_REQUIRED));
        // The command's name comes from its attribute, which SuperKernel's command registration reads.
        $command = new NewCommand(new ComposerLocator(new ManagedComposer(
            new Home(),
            new PhpReleases(new ReleaseIndex(new DailyCache(new Home()))),
            new RuntimeProvider(
                new Home(),
                new StaticPhpCli(new Home(), new ZigMirrors(new DailyCache(new Home()))),
                new ExtensionVersions(new DailyCache(new Home())),
                $this->prebuiltRuntime(),
            ),
        )));
        $command->setName('new');
        $application->addCommand($command);

        return new ApplicationTester($application)->run([
            'command'       => 'new',
            'directory'     => 'my-app',
            '--working-dir' => $this->home,
            '--composer'    => $this->home . '/bin/composer',
        ]);
    }
}
