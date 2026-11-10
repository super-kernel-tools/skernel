<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Configuration\ConfigurationLoader;
use Src\Configuration\PackageConfiguration;
use Src\Configuration\ProjectConfiguration;
use Symfony\Component\Console\Exception\RuntimeException;

use function array_keys;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function json_decode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const GLOB_BRACE;

final class ConfigurationLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'skernel-configuration-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory, 0o700));
        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{,.}*.json*', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testAProjectWithoutAConfigurationFileLeavesEverySettingOpen(): void
    {
        self::assertEquals(new ProjectConfiguration(), new ConfigurationLoader()->load($this->directory));
    }

    public function testReadsEverySettingAndResolvesFilePathsAgainstTheProject(): void
    {
        $this->write('skernel.json', <<<'JSON'
            {
                "$schema": "https://example.com/skernel.schema.json",
                "php": "8.4",
                "package": {
                    "runtime": "build/micro.sfx",
                    "output": "/opt/my-app",
                    "composer": "composer2",
                    "dev": true,
                    "phar": false
                }
            }
            JSON);

        self::assertEquals(new ProjectConfiguration('8.4', new PackageConfiguration(
            $this->directory . '/build/micro.sfx',
            '/opt/my-app',
            'composer2',
            true,
            false,
        )), new ConfigurationLoader()->load($this->directory));
    }

    public function testTheDistributedFileIsReadOnlyWhenTheLocalOneIsAbsent(): void
    {
        $this->write('skernel.json.dist', '{"php": "8.3", "package": {"dev": true}}');

        self::assertEquals(
            new ProjectConfiguration('8.3', new PackageConfiguration(dev: true)),
            new ConfigurationLoader()->load($this->directory),
        );

        $this->write('skernel.json', '{"php": "8.4.27"}');

        self::assertEquals(new ProjectConfiguration('8.4.27'), new ConfigurationLoader()->load($this->directory));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidFiles(): array
    {
        return [
            'list root' => [
                '[]',
                'skernel.json must contain a JSON object.',
            ],
            'unknown setting' => [
                '{"name": "my-app"}',
                'skernel.json: unknown setting "name".',
            ],
            'unknown package' => [
                '{"package": {"target": "dist"}}',
                'skernel.json: unknown setting "package.target".',
            ],
            'package list' => [
                '{"package": []}',
                'skernel.json: "package" must be an object.',
            ],
            'binary as php' => [
                '{"php": "php8.4"}',
                'skernel.json: "php" must be a PHP version such as 8.4 or 8.4.27.',
            ],
            'numeric php' => [
                '{"php": 8.4}',
                'skernel.json: "php" must be a PHP version such as 8.4 or 8.4.27.',
            ],
            'string flag' => [
                '{"package": {"dev": "yes"}}',
                'skernel.json: "package.dev" must be true or false.',
            ],
            'empty runtime' => [
                '{"package": {"runtime": ""}}',
                'skernel.json: "package.runtime" must be a non-empty string.',
            ],
            'malformed schema' => [
                '{"$schema": 1}',
                'skernel.json: "$schema" must be a non-empty string.',
            ],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function testRejectsAnInvalidFile(string $contents, string $message): void
    {
        $this->write('skernel.json', $contents);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($message);

        new ConfigurationLoader()->load($this->directory);
    }

    public function testRejectsAFileThatIsNotJson(): void
    {
        $this->write('skernel.json', '{');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($this->directory . '/skernel.json does not contain valid JSON.');

        new ConfigurationLoader()->load($this->directory);
    }

    public function testTheSchemaDescribesExactlyTheAcceptedSettings(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/skernel.schema.json'), true);

        self::assertIsArray($schema);
        self::assertSame([
            '$schema',
            'php',
            'package',
        ], array_keys($schema['properties']));
        self::assertSame([
            'runtime',
            'output',
            'composer',
            'dev',
            'phar',
        ], array_keys($schema['properties']['package']['properties']));
        self::assertFalse($schema['additionalProperties']);
        self::assertFalse($schema['properties']['package']['additionalProperties']);
    }

    private function write(string $file, string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->directory . '/' . $file, $contents));
    }
}
