<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Configuration\ConfigurationLoader;
use Src\Configuration\PackageConfiguration;
use Src\Configuration\ProjectConfiguration;
use Src\Configuration\RunConfiguration;
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

    public function testAProjectWithoutAConfigurationFileGetsTheDefaults(): void
    {
        $configuration = new ConfigurationLoader()->load($this->directory);

        self::assertEquals(new ProjectConfiguration(), $configuration);
        self::assertNull($configuration->php);
        self::assertTrue($configuration->prebuilt);
        self::assertNull($configuration->composer);
        self::assertFalse($configuration->run->watch);
        self::assertSame([
            '*.php',
            '.env',
            '.env.*',
            'composer.json',
            'composer.lock',
        ], $configuration->run->watchFiles);
        self::assertEquals(new PackageConfiguration(null, null, false, false), $configuration->package);
    }

    public function testAnEmptyFileKeepsEveryDefault(): void
    {
        $this->write('skernel.json', '{"run": {}, "package": {}}');

        self::assertEquals(new ProjectConfiguration(), new ConfigurationLoader()->load($this->directory));
    }

    public function testReadsEverySettingAndResolvesFilePathsAgainstTheProject(): void
    {
        $this->write('skernel.json', <<<'JSON'
            {
                "$schema": "https://example.com/skernel.schema.json",
                "php": "8.4",
                "prebuilt": false,
                "composer": "tools/composer",
                "run": {
                    "watch": true,
                    "watch-files": ["*.php", "*.yaml"]
                },
                "package": {
                    "runtime": "build/micro.sfx",
                    "output": "/opt/my-app",
                    "dev": true,
                    "phar": true
                }
            }
            JSON);

        self::assertEquals(new ProjectConfiguration(
            '8.4',
            false,
            $this->directory . '/tools/composer',
            new RunConfiguration(true, [
                '*.php',
                '*.yaml',
            ]),
            new PackageConfiguration($this->directory . '/build/micro.sfx', '/opt/my-app', true, true),
        ), new ConfigurationLoader()->load($this->directory));
    }

    public function testAComposerWithoutASlashIsANameLookedUpInPath(): void
    {
        $this->write('skernel.json', '{"composer": "composer2", "run": {"watch": true}}');

        self::assertEquals(
            new ProjectConfiguration(composer: 'composer2', run: new RunConfiguration(true)),
            new ConfigurationLoader()->load($this->directory),
        );
    }

    public function testTheDistributedFileIsReadOnlyWhenTheLocalOneIsAbsent(): void
    {
        $this->write('skernel.json.dist', '{"php": "8.3", "package": {"dev": true}}');

        self::assertEquals(
            new ProjectConfiguration('8.3', package: new PackageConfiguration(dev: true)),
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
            'composer in package' => [
                '{"package": {"composer": "composer"}}',
                'skernel.json: unknown setting "package.composer".',
            ],
            'unknown run' => [
                '{"run": {"poll": 1}}',
                'skernel.json: unknown setting "run.poll".',
            ],
            'run list' => [
                '{"run": [true]}',
                'skernel.json: "run" must be an object.',
            ],
            'string watch' => [
                '{"run": {"watch": "yes"}}',
                'skernel.json: "run.watch" must be true or false.',
            ],
            'watched string' => [
                '{"run": {"watch-files": "*.php"}}',
                'skernel.json: "run.watch-files" must be a non-empty list of non-empty strings.',
            ],
            'no watched files' => [
                '{"run": {"watch-files": []}}',
                'skernel.json: "run.watch-files" must be a non-empty list of non-empty strings.',
            ],
            'empty watched file' => [
                '{"run": {"watch-files": ["*.php", ""]}}',
                'skernel.json: "run.watch-files" must be a non-empty list of non-empty strings.',
            ],
            'watched object' => [
                '{"run": {"watch-files": {"php": "*.php"}}}',
                'skernel.json: "run.watch-files" must be a non-empty list of non-empty strings.',
            ],
            'empty composer' => [
                '{"composer": ""}',
                'skernel.json: "composer" must be a non-empty string.',
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
            'string prebuilt' => [
                '{"prebuilt": "no"}',
                'skernel.json: "prebuilt" must be true or false.',
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
            'prebuilt',
            'composer',
            'run',
            'package',
        ], array_keys($schema['properties']));
        self::assertSame([
            'watch',
            'watch-files',
        ], array_keys($schema['properties']['run']['properties']));
        self::assertSame([
            'runtime',
            'output',
            'dev',
            'phar',
        ], array_keys($schema['properties']['package']['properties']));
        self::assertFalse($schema['additionalProperties']);
        self::assertFalse($schema['properties']['run']['additionalProperties']);
        self::assertFalse($schema['properties']['package']['additionalProperties']);
        self::assertSame(
            new RunConfiguration()->watchFiles,
            $schema['properties']['run']['properties']['watch-files']['default'],
        );
    }

    private function write(string $file, string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->directory . '/' . $file, $contents));
    }
}
