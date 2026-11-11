<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use Src\Build\GeneratorLocator;
use Src\Build\MetadataGenerator;
use Src\Build\ProjectBuilder;
use Src\Filesystem\Directory;
use Src\Project\Project;
use Src\Tests\Support\BuildApiStandIn;
use Src\Toolchain\Home;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

use function chmod;
use function escapeshellarg;
use function file_put_contents;
use function implode;
use function is_dir;
use function json_encode;
use function mkdir;
use function realpath;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

use const PHP_BINARY;

final class ProjectBuilderTest extends TestCase
{
    private const string CONTAINER = <<<'PHP'
        namespace SuperKernel\Context {
            final class ApplicationContext
            {
                public static function getContainer(): object
                {
                    echo "container\n";

                    return new class() {
                        public function has(string $id): bool
                        {
                            return %s && 'Psr\EventDispatcher\EventDispatcherInterface' === $id;
                        }

                        public function get(string $id): object
                        {
                            return new class() {
                                public function dispatch(object $event): object
                                {
                                    echo 'dispatched ', $event::class, "\n";

                                    return $event;
                                }
                            };
                        }
                    };
                }
            }
        }

        PHP;

    private const string EVENT = <<<'PHP'
        namespace SuperKernel\Event {
            final class ApplicationBuild
            {
            }
        }

        PHP;

    private const string LOADER = <<<'PHP'
        namespace {
            if (%s) {
                require __DIR__ . '/build-api.php';
            }

            spl_autoload_register(static function (string $class): void {
                if (str_starts_with($class, 'App\\')) {
                    require dirname(__DIR__) . '/src/' . substr($class, 4) . '.php';
                }
            });
        }

        PHP;

    /** Loads only the classes in the class map that the stand-in Composer writes, as an authoritative class map does */
    private const string AUTHORITATIVE_LOADER = <<<'PHP'
        namespace {
            require __DIR__ . '/build-api.php';

            $classMap = is_file(__DIR__ . '/composer/classmap.php') ? require __DIR__ . '/composer/classmap.php' : [];

            spl_autoload_register(static function (string $class) use ($classMap): void {
                if (isset($classMap[$class])) {
                    require $classMap[$class];
                }
            });
        }

        PHP;

    /** Maps every class under `src/` of the project in the working directory, as Composer's dump-autoload would */
    private const string DUMP = <<<'PHP'
        <?php

        $classMap = [];

        foreach (glob(getcwd() . '/src/*.php') as $file) {
            $classMap['App\\' . basename($file, '.php')] = $file;
        }

        file_put_contents(
            getcwd() . '/vendor/composer/classmap.php',
            '<?php return ' . var_export($classMap, true) . ';',
        );

        PHP;

    private string $directory;

    private string $project;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'skernel-builder-test-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory, 0o700));
        $this->directory = (string) realpath($directory);
        $this->project = $this->directory . '/project';
        self::assertNotFalse(file_put_contents($this->directory . '/dump.php', self::DUMP));
        $this->composer('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->directory . '/dump.php'));
    }

    protected function tearDown(): void
    {
        Directory::remove($this->directory);
    }

    public function testRejectsAPhpBinaryThatIsNotFound(): void
    {
        $project = $this->project(true, true);
        $output = new BufferedOutput();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The PHP binary skernel-missing-php was not found.');

        try {
            $this->builder()->build($project, 'skernel-missing-php', $this->command(), '1.0.0', $output);
        } finally {
            self::assertSame('', $output->fetch());
        }
    }

    public function testGeneratesTheArtifactsAndPublishesTheBuildEventAfterCreatingTheContainer(): void
    {
        $output = new BufferedOutput();
        $project = $this->project(true, true);
        $this->builder()->build($project, PHP_BINARY, $this->command(), '1.2.3', $output);

        self::assertSame("container\ndispatched SuperKernel\\Event\\ApplicationBuild\n", $output->fetch());
        self::assertStringEqualsFile($project->vendorDirectory . '/.cache/build.json', '1.2.3');
    }

    public function testStampsDevWhenTheRunningSkernelHasNoVersion(): void
    {
        $project = $this->project(true, true);
        $this->builder()->build($project, PHP_BINARY, $this->command(), 'UNKNOWN', new BufferedOutput());

        self::assertStringEqualsFile($project->vendorDirectory . '/.cache/build.json', 'dev');
    }

    public function testSkipsTheBuildEventWithoutADispatcher(): void
    {
        $output = new BufferedOutput();
        $this->builder()->build($this->project(true, false), PHP_BINARY, $this->command(), '1.0.0', $output);

        self::assertSame("container\n", $output->fetch());
    }

    public function testSkipsTheBuildEventWhenTheProjectDoesNotDeclareIt(): void
    {
        $output = new BufferedOutput();
        $this->builder()->build($this->project(false, true), PHP_BINARY, $this->command(), '1.0.0', $output);

        self::assertSame("container\n", $output->fetch());
    }

    public function testListsSkippedClassesAfterTheBuildAndSkipsAClassThatEndsTheProcess(): void
    {
        $project = $this->project(false, false);
        $this->source('Broken', '#[Missing] final class Broken {}');
        $this->source('Fatal', '#[\Attribute] final class Fatal implements \Countable {}');
        $this->source('Kept', '#[\Attribute] final class Kept {}');
        $output = new BufferedOutput();
        $this->builder()->build($project, PHP_BINARY, $this->command(), '1.0.0', $output);

        self::assertSame(
            "container\n"
            . "SKERNEL skipped 2 classes whose attributes could not be collected:\n"
            . "  acme/app App\\Broken: Error: Attribute class \"App\\Missing\" not found\n"
            . '  acme/app App\\Fatal: fatal error: Class App\\Fatal contains 1 abstract method and must therefore be '
            . "declared abstract or implement the remaining methods (Countable::count)\n",
            $output->fetch(),
        );
    }

    public function testRegeneratesTheComposerAutoloaderBeforeCollectingAttributes(): void
    {
        $project = $this->project(false, false, authoritative: true);
        $this->source('Added', '#[\Attribute] final class Added {}');
        $output = new BufferedOutput();
        $this->builder()->build($project, PHP_BINARY, $this->command(), '1.0.0', $output);

        self::assertSame("container\n", $output->fetch());
        self::assertStringEqualsFile(
            $this->directory . '/composer-arguments',
            $project->directory . " dump-autoload --no-interaction --no-dev\n",
        );
    }

    public function testKeepsTheDevelopmentAutoloadRulesOfADevelopmentInstallation(): void
    {
        $project = $this->project(false, false, dev: true);
        $this->builder()->build($project, PHP_BINARY, $this->command(), '1.0.0', new BufferedOutput());

        self::assertStringEqualsFile(
            $this->directory . '/composer-arguments',
            $project->directory . " dump-autoload --no-interaction --dev\n",
        );
    }

    public function testStopsBeforeGeneratingWhenComposerFails(): void
    {
        $project = $this->project(true, true);
        $this->composer('echo "composer error" >&2; exit 3');
        $output = new BufferedOutput();

        try {
            $this->builder()->build($project, PHP_BINARY, $this->command(), '1.0.0', $output);
            self::fail('Built a project whose autoloader could not be regenerated.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Regenerating the Composer autoloader failed with exit status 3.',
                $exception->getMessage(),
            );
        }

        self::assertSame("composer error\n", $output->fetch());
        self::assertFileDoesNotExist($project->vendorDirectory . '/.cache/build.json');
    }

    public function testRejectsAProjectWithoutTheMetadataPublisher(): void
    {
        $project = $this->project(true, true, false);
        $output = new BufferedOutput();

        try {
            $this->builder()->build($project, PHP_BINARY, $this->command(), '1.0.0', $output);
            self::fail('Built a project without super-kernel/metadata.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith(
                'The project does not install super-kernel/metadata',
                $exception->getMessage(),
            );
        }

        self::assertSame('', $output->fetch());
    }

    private function builder(): ProjectBuilder
    {
        return new ProjectBuilder(new MetadataGenerator(new GeneratorLocator(new Home())));
    }

    /** @return non-empty-list<string> The command that runs the stand-in Composer */
    private function command(): array
    {
        return [
            $this->directory . '/bin/composer',
        ];
    }

    /**
     * Writes the stand-in Composer, which records its working directory and arguments and then runs the given shell
     * code.
     */
    private function composer(string $code): void
    {
        if (!is_dir($this->directory . '/bin')) {
            self::assertTrue(mkdir($this->directory . '/bin', 0o700));
        }

        self::assertNotFalse(file_put_contents($this->directory . '/bin/composer', implode("\n", [
            '#!/bin/sh',
            'echo "$PWD $*" >> ' . escapeshellarg($this->directory . '/composer-arguments'),
            $code,
            '',
        ])));
        self::assertTrue(chmod($this->directory . '/bin/composer', 0o755));
    }

    /**
     * Writes a temporary project named acme/app, whose autoloader declares a recording container in place of
     * SuperKernel's and maps `App\` to `src/`, or with an authoritative class map loads only the classes that the
     * stand-in Composer mapped.
     */
    private function project(
        bool $event,
        bool $dispatcher,
        bool $metadata = true,
        bool $authoritative = false,
        bool $dev = false,
    ): Project
    {
        self::assertTrue(mkdir($this->project . '/src', 0o700, true));
        self::assertNotFalse(file_put_contents(
            $this->project . '/composer.json',
            '{"name": "acme/app", "autoload": {"psr-4": {"App\\\\": "src/"}}}',
        ));
        BuildApiStandIn::write($this->project, $this->project . '/vendor');
        self::assertNotFalse(file_put_contents(
            $this->project . '/vendor/composer/installed.json',
            (string) json_encode([
                'packages' => [],
                'dev'      => $dev,
            ]),
        ));
        $autoload = "<?php\n\n" . sprintf(self::CONTAINER, var_export($dispatcher, true)) . ($event ? self::EVENT : '')
            . ($authoritative ? self::AUTHORITATIVE_LOADER : sprintf(self::LOADER, var_export($metadata, true)));
        self::assertNotFalse(file_put_contents($this->project . '/vendor/autoload.php', $autoload));

        return new Project($this->project, $this->project . '/vendor', 'acme/app', null);
    }

    private function source(string $class, string $declaration): void
    {
        self::assertNotFalse(file_put_contents(
            $this->project . '/src/' . $class . '.php',
            "<?php\n\nnamespace App;\n\n" . $declaration . "\n",
        ));
    }
}
