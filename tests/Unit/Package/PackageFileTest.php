<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Package;

use PHPUnit\Framework\TestCase;
use Src\Filesystem\Directory;
use Src\Package\PackageFile;

use function file_put_contents;
use function fileperms;
use function glob;
use function mkdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const GLOB_BRACE;

final class PackageFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'skernel-package-file-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory, 0o700));
        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        Directory::remove($this->directory);
    }

    public function testWritesTheConcatenatedPartsAsAnExecutableIntoANewDirectory(): void
    {
        self::assertNotFalse(file_put_contents($this->directory . '/micro.sfx', 'MICRO'));
        self::assertNotFalse(file_put_contents($this->directory . '/app.phar', 'PHAR'));

        PackageFile::write($this->directory . '/target/app', [
            $this->directory . '/micro.sfx',
            $this->directory . '/app.phar',
        ]);

        self::assertStringEqualsFile($this->directory . '/target/app', 'MICROPHAR');
        self::assertSame(0o755, fileperms($this->directory . '/target/app') & 0o777);
        self::assertSame([
            $this->directory . '/target/app',
        ], glob($this->directory . '/target/{,.}*[!.]', GLOB_BRACE));
    }
}
