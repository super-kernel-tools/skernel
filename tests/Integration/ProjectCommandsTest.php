<?php

declare(strict_types=1);

namespace Src\Tests\Integration;

use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;
use Src\Filesystem\Directory;
use Src\Toolchain\DailyCache;
use Src\Toolchain\ExtensionRequirements;
use Src\Toolchain\ExtensionVersions;
use Src\Toolchain\Home;
use Src\Toolchain\RuntimeProvider;
use Src\Toolchain\StaticPhpCli;
use Src\Toolchain\ZigMirrors;
use Symfony\Component\Process\Process;

use function chmod;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function json_encode;
use function microtime;
use function mkdir;
use function preg_replace;
use function putenv;
use function realpath;
use function str_contains;
use function substr_count;
use function symlink;
use function sys_get_temp_dir;
use function tempnam;
use function touch;
use function unlink;
use function usleep;

use const PHP_BINARY;
use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;
use const PHP_RELEASE_VERSION;
use const SIGTERM;

/**
 * Runs the clean, new, run, and self-update commands on temporary projects, whose managed PHP is the running PHP and
 * whose application is a stand-in, so that nothing is downloaded or compiled.
 */
#[RequiresOperatingSystemFamily('Linux')]
final class ProjectCommandsTest extends TestCase
{
    private const string AUTOLOAD = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace SuperKernel\Context;

        final class ApplicationContext
        {
            public static function getContainer(): object
            {
                return new class() {
                    public function get(string $id): object
                    {
                        return new class() {
                            public function run(): never
                            {
                                $arguments = implode(' ', array_slice($_SERVER['argv'], 1));
                                echo 'started in ', basename(getcwd()), ' with ', $arguments, "\n";
                                fwrite(STDERR, "application error output\n");

                                if (in_array('sleep', $_SERVER['argv'], true)) {
                                    sleep(60);
                                }

                                exit(in_array('fail', $_SERVER['argv'], true) ? 7 : 0);
                            }
                        };
                    }
                };
            }
        }

        PHP;

    private string $directory;

    private string $project;

    private string $version;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'skernel-commands-test-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory, 0o700));
        $this->directory = (string) realpath($directory);
        $this->project = $this->directory . '/project';
        $this->version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        $this->write($this->project . '/composer.json', (string) json_encode([
            'name' => 'acme/demo',
        ]));
        $this->write($this->project . '/vendor/autoload.php', self::AUTOLOAD);
        $this->write($this->project . '/vendor/composer/installed.json', (string) json_encode([
            'packages' => [
                [
                    'name' => 'super-kernel/context',
                ],
                [
                    'name' => 'super-kernel/di',
                ],
            ],
            'dev' => false,
        ]));
        $this->write($this->project . '/src/App.php', '<?php');
        putenv('SKERNEL_HOME=' . $this->directory . '/home');

        try {
            $runtime = new RuntimeProvider(
                new Home(),
                new StaticPhpCli(new Home(), new ZigMirrors(new DailyCache(new Home()))),
                new ExtensionVersions(new DailyCache(new Home())),
            )->directory($this->version, new ExtensionRequirements([
                'phar',
            ]));
        } finally {
            putenv('SKERNEL_HOME');
        }

        self::assertTrue(mkdir($runtime, 0o700, true));
        self::assertTrue(symlink(PHP_BINARY, $runtime . '/php'));
        self::assertTrue(touch($runtime . '/micro.sfx') && touch($runtime . '/manifest.json'));
    }

    protected function tearDown(): void
    {
        Directory::remove($this->directory);
    }

    public function testCleanRemovesTheBuildOutputAndTheScanCaches(): void
    {
        $this->write($this->project . '/target/demo', 'package');
        $this->write($this->project . '/vendor/.cache/classes/demo.ser', 'cache');

        self::assertSame([
            'Removed ' . $this->project . '/target.',
            'Removed ' . $this->project . '/vendor/.cache.',
        ], $this->skernel('clean -d ' . escapeshellarg($this->project), $status));
        self::assertSame(0, $status);
        self::assertDirectoryDoesNotExist($this->project . '/target');
        self::assertDirectoryDoesNotExist($this->project . '/vendor/.cache');
        self::assertFileExists($this->project . '/vendor/autoload.php');
        self::assertFileExists($this->project . '/src/App.php');

        self::assertSame([
            'Nothing to clean.',
        ], $this->skernel('clean -d ' . escapeshellarg($this->project), $status));
        self::assertSame(0, $status);
    }

    public function testCleanWorksOnAProjectWhoseDependenciesAreNotInstalled(): void
    {
        Directory::remove($this->project . '/vendor');
        $this->write($this->project . '/target/demo', 'package');

        self::assertSame([
            'Removed ' . $this->project . '/target.',
        ], $this->skernel('clean -d ' . escapeshellarg($this->project), $status));
        self::assertSame(0, $status);
    }

    public function testRunForwardsTheArgumentsOutputAndExitStatusOfTheApplication(): void
    {
        $arguments = 'run --php=' . $this->version . ' -d ' . escapeshellarg($this->project);

        self::assertSame([
            'started in project with serve --port=1',
        ], $this->skernel($arguments . ' -- serve --port=1', $status));
        self::assertSame(0, $status);

        self::assertSame([
            'application error output',
        ], $this->skernel($arguments . ' 2>&1 >/dev/null | grep application', $status, ''));

        $this->skernel($arguments . ' fail', $status);
        self::assertSame(7, $status);
    }

    public function testRunWithWatchRestartsOnAChangeAndStopsOnASignal(): void
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/bootstrap.php',
            'run',
            '--watch',
            '--php=' . $this->version,
            '-d',
            $this->project,
            'sleep',
        ], null, [
            'SKERNEL_HOME' => $this->directory . '/home',
        ], null, 60);
        $process->start();

        try {
            $this->waitFor(static fn(): bool => str_contains($process->getOutput(), 'started in project with sleep'));

            $this->write($this->project . '/runtime/app.log', 'not watched');
            usleep(1_300_000);
            self::assertSame(1, substr_count($process->getOutput(), 'started in project'));

            $this->write($this->project . '/src/App.php', '<?php // changed');
            $this->waitFor(static fn(): bool => 2 === substr_count($process->getOutput(), 'started in project'));
            self::assertStringContainsString('The project changed; restarting.', $process->getErrorOutput());

            $process->signal(SIGTERM);
            $process->wait();
            self::assertFalse($process->isRunning());
        } finally {
            $process->stop(1);
        }
    }

    public function testRunWithWatchWaitsForAChangeAfterTheApplicationExits(): void
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/bootstrap.php',
            'run',
            '--watch',
            '--php=' . $this->version,
            '-d',
            $this->project,
            'fail',
        ], null, [
            'SKERNEL_HOME' => $this->directory . '/home',
        ], null, 60);
        $process->start();

        try {
            $this->waitFor(static fn(): bool => str_contains(
                $process->getErrorOutput(),
                'The application exited with status 7; waiting for a change.',
            ));
            self::assertTrue($process->isRunning());

            $this->write($this->project . '/src/Fix.php', '<?php');
            $this->waitFor(static fn(): bool => 2 === substr_count($process->getOutput(), 'started in project'));
        } finally {
            $process->stop(1);
        }
    }

    public function testNewCreatesAProjectFromTheSkeletonWithComposer(): void
    {
        $this->write(
            $this->directory . '/bin/composer',
            "#!/bin/sh\necho \"\$*\" > " . escapeshellarg($this->directory . '/arguments') . "\n",
        );
        self::assertTrue(chmod($this->directory . '/bin/composer', 0o755));
        $target = $this->directory . '/my-app';

        self::assertSame([
            'Project created in ' . $target . '.',
        ], $this->skernel(
            'new --composer=' . escapeshellarg($this->directory . '/bin/composer') . ' ' . escapeshellarg($target),
            $status,
        ));
        self::assertSame(0, $status);
        self::assertSame(
            'create-project super-kernel/skeleton ' . $target
            . " --stability=dev --no-interaction --no-progress --ignore-platform-reqs\n",
            file_get_contents($this->directory . '/arguments'),
        );
    }

    public function testNewResolvesARelativeDirectoryAgainstTheWorkingDirectoryOption(): void
    {
        $this->write(
            $this->directory . '/bin/composer',
            "#!/bin/sh\necho \"\$*\" > " . escapeshellarg($this->directory . '/arguments') . "\n",
        );
        self::assertTrue(chmod($this->directory . '/bin/composer', 0o755));

        $this->skernel(
            'new my-app -d ' . escapeshellarg(
                $this->directory,
            ) . ' --composer=' . escapeshellarg($this->directory . '/bin/composer'),
            $status,
        );

        self::assertSame(0, $status);
        self::assertStringContainsString(
            ' ' . $this->directory . '/my-app ',
            (string) file_get_contents($this->directory . '/arguments'),
        );
    }

    public function testNewRefusesADirectoryInUseAndReportsAFailedComposer(): void
    {
        $this->write($this->directory . '/bin/composer', "#!/bin/sh\nexit 3\n");
        self::assertTrue(chmod($this->directory . '/bin/composer', 0o755));
        $composer = '--composer=' . escapeshellarg($this->directory . '/bin/composer');

        $output = $this->skernel('new ' . $composer . ' ' . escapeshellarg($this->project), $status, '2>&1 >/dev/null');
        self::assertSame(1, $status);
        self::assertStringContainsString(
            'Thedirectory' . $this->project . 'alreadyexistsandisnotempty.',
            (string) preg_replace('/\s+/', '', implode('', $output)),
        );

        $output = $this->skernel(
            'new ' . $composer . ' ' . escapeshellarg($this->directory . '/app'),
            $status,
            '2>&1 >/dev/null',
        );
        self::assertSame(1, $status);
        self::assertStringContainsString(
            'Creatingtheprojectfailedwithexitstatus3.',
            (string) preg_replace('/\s+/', '', implode('', $output)),
        );
    }

    public function testSelfUpdateRefusesToReplaceASourceCheckout(): void
    {
        $output = $this->skernel('self-update', $status, '2>&1 >/dev/null');

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'SKERNEL is running from source; update it with Git instead.',
            implode(' ', $output),
        );
    }

    /** @return list<string> */
    private function skernel(string $arguments, ?int &$status, string $redirection = '2>/dev/null'): array
    {
        exec(
            'SKERNEL_HOME=' . escapeshellarg($this->directory . '/home') . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/bootstrap.php')
            . ' ' . $arguments . ' ' . $redirection,
            $output,
            $status,
        );

        return $output;
    }

    /** @param callable(): bool $condition */
    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 20;

        while (!$condition()) {
            self::assertLessThan($deadline, microtime(true), 'Timed out waiting for the application.');
            usleep(50_000);
        }
    }

    private function write(string $path, string $contents): void
    {
        @mkdir(dirname($path), 0o700, true);
        self::assertNotFalse(file_put_contents($path, $contents));
    }
}
