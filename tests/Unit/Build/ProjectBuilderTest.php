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

use function dirname;
use function file_put_contents;
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

    private ?string $directory = null;

    protected function tearDown(): void
    {
        if (null !== $this->directory) {
            Directory::remove($this->directory);
        }
    }

    public function testRejectsAPhpBinaryThatIsNotFound(): void
    {
        $root = dirname(__DIR__, 3);
        $output = new BufferedOutput();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The PHP binary skernel-missing-php was not found.');

        try {
            $this->builder()->build(
                new Project($root, $root . '/vendor', null, null),
                'skernel-missing-php',
                '1.0.0',
                $output,
            );
        } finally {
            self::assertSame('', $output->fetch());
        }
    }

    public function testGeneratesTheArtifactsAndPublishesTheBuildEventAfterCreatingTheContainer(): void
    {
        $output = new BufferedOutput();
        $project = $this->project(true, true);
        $this->builder()->build($project, PHP_BINARY, '1.2.3', $output);

        self::assertSame("container\ndispatched SuperKernel\\Event\\ApplicationBuild\n", $output->fetch());
        self::assertStringEqualsFile($project->vendorDirectory . '/.cache/build.json', '1.2.3');
    }

    public function testStampsDevWhenTheRunningSkernelHasNoVersion(): void
    {
        $project = $this->project(true, true);
        $this->builder()->build($project, PHP_BINARY, 'UNKNOWN', new BufferedOutput());

        self::assertStringEqualsFile($project->vendorDirectory . '/.cache/build.json', 'dev');
    }

    public function testSkipsTheBuildEventWithoutADispatcher(): void
    {
        $output = new BufferedOutput();
        $this->builder()->build($this->project(true, false), PHP_BINARY, '1.0.0', $output);

        self::assertSame("container\n", $output->fetch());
    }

    public function testSkipsTheBuildEventWhenTheProjectDoesNotDeclareIt(): void
    {
        $output = new BufferedOutput();
        $this->builder()->build($this->project(false, true), PHP_BINARY, '1.0.0', $output);

        self::assertSame("container\n", $output->fetch());
    }

    public function testListsSkippedClassesAfterTheBuildAndSkipsAClassThatEndsTheProcess(): void
    {
        $project = $this->project(false, false);
        $this->source('Broken', '#[Missing] final class Broken {}');
        $this->source('Fatal', '#[\Attribute] final class Fatal implements \Countable {}');
        $this->source('Kept', '#[\Attribute] final class Kept {}');
        $output = new BufferedOutput();
        $this->builder()->build($project, PHP_BINARY, '1.0.0', $output);

        self::assertSame(
            "container\n"
            . "SKERNEL skipped 2 classes whose attributes could not be collected:\n"
            . "  acme/app App\\Broken: Error: Attribute class \"App\\Missing\" not found\n"
            . '  acme/app App\\Fatal: fatal error: Class App\\Fatal contains 1 abstract method and must therefore be '
            . "declared abstract or implement the remaining methods (Countable::count)\n",
            $output->fetch(),
        );
    }

    public function testRejectsAProjectWithoutTheMetadataPublisher(): void
    {
        $project = $this->project(true, true, false);
        $output = new BufferedOutput();

        try {
            $this->builder()->build($project, PHP_BINARY, '1.0.0', $output);
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

    /**
     * Writes a temporary project named acme/app, whose autoloader declares a recording container in place of
     * SuperKernel's and maps `App\` to `src/`.
     */
    private function project(bool $event, bool $dispatcher, bool $metadata = true): Project
    {
        $directory = tempnam(sys_get_temp_dir(), 'skernel-project-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory . '/src', 0o700, true));
        $directory = (string) realpath($directory);
        $this->directory = $directory;
        self::assertNotFalse(file_put_contents(
            $directory . '/composer.json',
            '{"name": "acme/app", "autoload": {"psr-4": {"App\\\\": "src/"}}}',
        ));
        BuildApiStandIn::write($directory, $directory . '/vendor');
        $autoload = "<?php\n\n" . sprintf(self::CONTAINER, var_export($dispatcher, true)) . ($event ? self::EVENT : '')
            . sprintf(self::LOADER, var_export($metadata, true));
        self::assertNotFalse(file_put_contents($directory . '/vendor/autoload.php', $autoload));

        return new Project($directory, $directory . '/vendor', 'acme/app', null);
    }

    private function source(string $class, string $declaration): void
    {
        self::assertNotFalse(file_put_contents(
            $this->directory . '/src/' . $class . '.php',
            "<?php\n\nnamespace App;\n\n" . $declaration . "\n",
        ));
    }
}
