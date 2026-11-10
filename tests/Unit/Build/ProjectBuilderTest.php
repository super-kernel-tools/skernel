<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use Src\Build\ProjectBuilder;
use Src\Project\Project;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

use function dirname;
use function file_put_contents;
use function mkdir;
use function rmdir;
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

    private ?string $directory = null;

    protected function tearDown(): void
    {
        if (null !== $this->directory) {
            unlink($this->directory . '/vendor/autoload.php');
            rmdir($this->directory . '/vendor');
            rmdir($this->directory);
        }
    }

    public function testRejectsAPhpBinaryThatIsNotFound(): void
    {
        $root = dirname(__DIR__, 3);
        $output = new BufferedOutput();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The PHP binary skernel-missing-php was not found.');

        try {
            new ProjectBuilder()->build(
                new Project($root, $root . '/vendor', null, null),
                'skernel-missing-php',
                $output,
            );
        } finally {
            self::assertSame('', $output->fetch());
        }
    }

    public function testPublishesTheBuildEventAfterCreatingTheContainer(): void
    {
        $output = new BufferedOutput();

        self::assertSame(0, new ProjectBuilder()->build($this->project(true, true), PHP_BINARY, $output));
        self::assertSame("container\ndispatched SuperKernel\\Event\\ApplicationBuild\n", $output->fetch());
    }

    public function testSkipsTheBuildEventWithoutADispatcher(): void
    {
        $output = new BufferedOutput();

        self::assertSame(0, new ProjectBuilder()->build($this->project(true, false), PHP_BINARY, $output));
        self::assertSame("container\n", $output->fetch());
    }

    public function testSkipsTheBuildEventWhenTheProjectDoesNotDeclareIt(): void
    {
        $output = new BufferedOutput();

        self::assertSame(0, new ProjectBuilder()->build($this->project(false, true), PHP_BINARY, $output));
        self::assertSame("container\n", $output->fetch());
    }

    /** Writes a temporary project whose autoloader declares a recording container in place of SuperKernel's. */
    private function project(bool $event, bool $dispatcher): Project
    {
        $directory = tempnam(sys_get_temp_dir(), 'skernel-project-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory . '/vendor', 0o700, true));
        $this->directory = $directory;
        $autoload = "<?php\n\n" . sprintf(self::CONTAINER, var_export($dispatcher, true)) . ($event ? self::EVENT : '');
        self::assertNotFalse(file_put_contents($directory . '/vendor/autoload.php', $autoload));

        return new Project($directory, $directory . '/vendor', null, null);
    }
}
