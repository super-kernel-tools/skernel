<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Package;

use PHPUnit\Framework\TestCase;
use Src\Filesystem\Directory;
use Src\Package\ProjectCopier;

use function dirname;
use function file_put_contents;
use function mkdir;
use function realpath;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class ProjectCopierTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'skernel-copy-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory . '/project/src/.git', 0o700, true));
        $this->directory = (string) realpath($directory);
    }

    protected function tearDown(): void
    {
        Directory::remove($this->directory);
    }

    public function testCopiesTheProjectWithoutMetadataSecretsOrExcludedPaths(): void
    {
        $project = $this->directory . '/project';

        foreach ([
            'composer.json',
            'src/App.php',
            'src/.git/HEAD',
            'src/.env',
            '.env',
            '.env.local',
            '.environment',
            'vendor/autoload.php',
            'target/demo',
        ] as $file) {
            @mkdir(dirname($project . '/' . $file), 0o700, true);
            self::assertNotFalse(file_put_contents($project . '/' . $file, $file));
        }

        ProjectCopier::copy($project, $this->directory . '/copy', [
            $project . '/vendor',
            $project . '/target',
        ]);

        foreach ([
            'composer.json',
            'src/App.php',
            'src/.env',
            '.environment',
        ] as $file) {
            self::assertStringEqualsFile($this->directory . '/copy/' . $file, $file);
        }

        foreach ([
            'src/.git',
            '.env',
            '.env.local',
            'vendor',
            'target',
        ] as $file) {
            self::assertFileDoesNotExist($this->directory . '/copy/' . $file);
        }
    }
}
