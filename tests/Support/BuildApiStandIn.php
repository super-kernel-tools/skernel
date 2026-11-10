<?php

declare(strict_types=1);

namespace Src\Tests\Support;

use PHPUnit\Framework\Assert;

use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;

/**
 * Writes what the generator needs from an installed project into a stand-in vendor directory: Composer's installation
 * state and stand-ins for the super-kernel/metadata and annotator publish API, whose stamp records the SKERNEL
 * version it was given.
 */
final class BuildApiStandIn
{
    private const string SOURCE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace SuperKernel\Metadata\Constants {
            final class Protocol
            {
                public const int VERSION = 1;
            }
        }

        namespace SuperKernel\Metadata\Path {
            final class BasePath
            {
                public function __construct(public string $directory)
                {
                }
            }

            final class VendorDir
            {
                public function __construct(public string $directory)
                {
                }
            }
        }

        namespace SuperKernel\Metadata\Model {
            final class Package
            {
                public function __construct(mixed ...$arguments)
                {
                }
            }

            final class EnvironmentRequirement
            {
                public function __construct(mixed ...$arguments)
                {
                }
            }
        }

        namespace SuperKernel\Metadata\Manager {
            final class PackageManager
            {
                public function __construct(mixed ...$arguments)
                {
                }
            }
        }

        namespace SuperKernel\Metadata\Build {
            final class MetadataPublisher
            {
                public function __construct(
                    private \SuperKernel\Metadata\Path\VendorDir $vendorDir,
                    \SuperKernel\Metadata\Path\BasePath $basePath,
                ) {
                }

                public function invalidate(): void
                {
                }

                public function publishPackages(object $manager): void
                {
                }

                public function commit(string $skernelVersion): void
                {
                    @mkdir($this->vendorDir->directory . '/.cache');
                    file_put_contents($this->vendorDir->directory . '/.cache/build.json', $skernelVersion);
                }
            }
        }

        namespace SuperKernel\Annotator\Model {
            final class AttributeMetadata
            {
                public function __construct(mixed ...$arguments)
                {
                }
            }

            final class PackageAnnotations
            {
                public function __construct(mixed ...$arguments)
                {
                }
            }
        }

        namespace SuperKernel\Annotator\Cache {
            final class AnnotationPublisher
            {
                public function __construct(mixed ...$arguments)
                {
                }

                public function publish(object $manager, array $annotations): void
                {
                }
            }
        }

        PHP;

    /**
     * Writes `build-api.php`, which the stand-in autoloader requires, and an installation state without packages; a
     * project without a lock file also gets an empty one.
     */
    public static function write(string $projectDirectory, string $vendorDirectory): void
    {
        if (!is_dir($vendorDirectory . '/composer')) {
            Assert::assertTrue(mkdir($vendorDirectory . '/composer', 0o700, true));
        }

        Assert::assertNotFalse(file_put_contents($vendorDirectory . '/build-api.php', self::SOURCE));
        Assert::assertNotFalse(file_put_contents(
            $vendorDirectory . '/composer/installed.php',
            "<?php return ['root' => ['dev' => false], 'versions' => []];",
        ));

        if (!is_file($projectDirectory . '/composer.lock')) {
            Assert::assertNotFalse(file_put_contents(
                $projectDirectory . '/composer.lock',
                '{"packages": [], "packages-dev": []}',
            ));
        }
    }
}
