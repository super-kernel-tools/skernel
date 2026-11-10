<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Generator;

use JsonException;
use PHPUnit\Framework\TestCase;
use Src\Filesystem\Directory;

use function dirname;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function realpath;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Provides a temporary directory and file writers for generator tests, whose fixtures are generated so that SKERNEL's
 * own build never scans them.
 */
abstract class GeneratorTestCase extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'skernel-generator-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory, 0o700));
        $this->directory = (string) realpath($directory);
    }

    protected function tearDown(): void
    {
        Directory::remove($this->directory);
    }

    /**
     * Writes a file below the temporary directory, creating its parents.
     */
    protected function write(string $path, string $contents): string
    {
        $file = $this->directory . '/' . $path;

        if (!is_dir(dirname($file))) {
            self::assertTrue(mkdir(dirname($file), 0o700, true));
        }

        self::assertNotFalse(file_put_contents($file, $contents));

        return $file;
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @throws JsonException When the document cannot be encoded
     */
    protected function writeJson(string $path, array $document): string
    {
        return $this->write($path, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
