<?php

declare(strict_types=1);

namespace Src\Tests\Integration;

use Phar;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use Src\Filesystem\Directory;
use Src\Toolchain\DailyCache;
use Src\Toolchain\ExtensionRequirements;
use Src\Toolchain\ExtensionVersions;
use Src\Toolchain\Home;
use Src\Toolchain\RuntimeProvider;
use Src\Toolchain\StaticPhpCli;
use Src\Toolchain\ZigMirrors;

use function chmod;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function json_encode;
use function mkdir;
use function preg_replace;
use function putenv;
use function realpath;
use function sort;
use function str_starts_with;
use function strlen;
use function substr;
use function symlink;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_BINARY;
use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;
use const PHP_RELEASE_VERSION;

/**
 * Packages a temporary project with a recording Composer script and a managed runtime provisioned from the running
 * PHP, so that nothing is downloaded or compiled.
 */
#[RequiresOperatingSystemFamily('Linux')]
final class PackageCommandTest extends TestCase
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
                    public function has(string $id): bool
                    {
                        return false;
                    }

                    public function get(string $id): object
                    {
                        return new class() {
                            public function run(): void
                            {
                                echo 'running from ', basename(\Phar::running(false)) ?: 'source', "\n";
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
        $directory = tempnam(sys_get_temp_dir(), 'skernel-package-test-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory, 0o700));
        $this->directory = (string) realpath($directory);
        $this->project = $this->directory . '/project';
        $this->version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        $this->vendor($this->directory . '/template');
        $this->vendor($this->project);
        $this->write($this->project . '/composer.json', (string) json_encode([
            'name'    => 'acme/demo',
            'require' => [
                'php' => '>=8.4',
            ],
        ]));
        $this->write($this->project . '/composer.lock', '{}');
        $this->write($this->project . '/src/App.php', '<?php');
        $this->write($this->project . '/.env', 'SECRET=1');
        $this->write($this->project . '/.git/HEAD', 'ref: refs/heads/main');
        $this->write($this->project . '/target/stale', 'stale');
        $this->write($this->directory . '/bin/composer', implode("\n", [
            '#!/bin/sh',
            'if [ "$1" = --version ]; then echo "Composer version 2.10.3"; exit 0; fi',
            'printf "%s" "$*" > ' . escapeshellarg($this->directory . '/composer-arguments'),
            'cp -R ' . escapeshellarg($this->directory . '/template/vendor') . ' vendor',
            '',
        ]));
        self::assertTrue(chmod($this->directory . '/bin/composer', 0o755));
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
        $this->write($runtime . '/micro.sfx', 'MICRO');
        $this->write($runtime . '/manifest.json', '{}');
    }

    protected function tearDown(): void
    {
        Directory::remove($this->directory);
    }

    public function testPackagesAPharWithoutMetadataSecretsOrOldOutput(): void
    {
        $output = $this->package('--phar --php=' . $this->version . ' --composer=' . $this->composer(), $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([
            'Package written to ' . $this->project . '/target/demo.phar.',
        ], $output);
        self::assertSame(
            'install --no-interaction --no-progress --ignore-platform-reqs --no-dev',
            file_get_contents($this->directory . '/composer-arguments'),
        );
        self::assertStringStartsWith(
            "#!/usr/bin/env php\n",
            (string) file_get_contents($this->project . '/target/demo.phar'),
        );
        self::assertSame([
            'composer.json',
            'composer.lock',
            'src/App.php',
            'vendor/autoload.php',
            'vendor/composer/installed.json',
        ], $this->entries($this->project . '/target/demo.phar'));

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->project . '/target/demo.phar'), $run, $status);

        self::assertSame(0, $status);
        self::assertSame([
            'running from demo.phar',
        ], $run);
    }

    public function testPackagesAStandaloneBinaryWithTheManagedMicroRuntime(): void
    {
        $output = $this->package('--dev --php=' . $this->version . ' --composer=' . $this->composer(), $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([
            'Package written to ' . $this->project . '/target/demo.',
        ], $output);
        self::assertSame(
            'install --no-interaction --no-progress --ignore-platform-reqs',
            file_get_contents($this->directory . '/composer-arguments'),
        );
        $binary = (string) file_get_contents($this->project . '/target/demo');
        self::assertStringStartsWith('MICRO<?php', $binary);
    }

    public function testTakesTheOptionsLeftOffTheCommandLineFromTheConfigurationFile(): void
    {
        $this->write($this->project . '/skernel.json.dist', (string) json_encode([
            'php'     => $this->version,
            'package' => [
                'composer' => $this->directory . '/bin/composer',
                'output'   => 'dist/demo.phar',
                'phar'     => true,
            ],
        ]));

        $output = $this->package('', $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([
            'Package written to ' . $this->project . '/dist/demo.phar.',
        ], $output);
        self::assertFileExists($this->project . '/dist/demo.phar');
    }

    public function testPackagesAProjectWhoseDependenciesAreNotInstalled(): void
    {
        Directory::remove($this->project . '/vendor');

        $output = $this->package('--phar --php=' . $this->version . ' --composer=' . $this->composer(), $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([
            'Package written to ' . $this->project . '/target/demo.phar.',
        ], $output);
        self::assertDirectoryDoesNotExist($this->project . '/vendor');
    }

    public function testFindsAWorkingComposerWhenNoneIsGiven(): void
    {
        exec(
            'PATH=' . escapeshellarg($this->directory . '/bin:/usr/bin:/bin')
            . ' HOME=' . escapeshellarg($this->directory)
            . ' SKERNEL_HOME=' . escapeshellarg($this->directory . '/home') . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/bootstrap.php')
            . ' package --phar --php=' . $this->version . ' -d ' . escapeshellarg($this->project) . ' 2>/dev/null',
            $output,
            $status,
        );

        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringStartsWith(
            'install --no-interaction',
            (string) file_get_contents($this->directory . '/composer-arguments'),
        );
    }

    public function testRejectsAProjectWithoutALockFile(): void
    {
        unlink($this->project . '/composer.lock');

        $output = $this->package('--phar', $status, '2>&1 >/dev/null');

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'The project in ' . $this->project . ' has no composer.lock; run composer install first.',
            (string) preg_replace('/\s+/', ' ', implode(' ', $output)),
        );
    }

    /** @return list<string> */
    private function package(string $options, ?int &$status, string $redirection = '2>/dev/null'): array
    {
        $command = 'SKERNEL_HOME=' . escapeshellarg($this->directory . '/home') . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/bootstrap.php')
            . ' package ' . $options . ' -d ' . escapeshellarg($this->project) . ' ' . $redirection;

        exec($command, $output, $status);

        return $output;
    }

    private function composer(): string
    {
        return escapeshellarg($this->directory . '/bin/composer');
    }

    private function vendor(string $directory): void
    {
        $this->write($directory . '/vendor/autoload.php', self::AUTOLOAD);
        $this->write($directory . '/vendor/composer/installed.json', (string) json_encode([
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
    }

    /** @return list<string> */
    private function entries(string $archive): array
    {
        $prefix = 'phar://' . $archive . '/';
        $entries = [];

        foreach (new RecursiveIteratorIterator(new Phar($archive)) as $entry) {
            $path = (string) $entry->getPathname();
            $entries[] = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
        }

        sort($entries);

        return $entries;
    }

    private function write(string $path, string $contents): void
    {
        @mkdir(dirname($path), 0o700, true);
        self::assertNotFalse(file_put_contents($path, $contents));
    }
}
