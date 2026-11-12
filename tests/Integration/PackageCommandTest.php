<?php

declare(strict_types=1);

namespace Src\Tests\Integration;

use Phar;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use Src\Filesystem\Directory;
use Src\Tests\Support\BuildApiStandIn;
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
use function substr_count;
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

        require __DIR__ . '/build-api.php';

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

    private const array PACKAGES = [
        [
            'name' => 'super-kernel/context',
        ],
        [
            'name' => 'super-kernel/di',
        ],
    ];

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
        $this->write($this->project . '/composer.lock', '{"packages": [], "packages-dev": []}');
        $this->write($this->project . '/src/App.php', '<?php');
        $this->write($this->project . '/.env', 'SECRET=1');
        $this->write($this->project . '/.git/HEAD', 'ref: refs/heads/main');
        $this->write($this->project . '/target/stale', 'stale');
        $this->write($this->directory . '/installed-dev.json', (string) json_encode([
            'packages' => self::PACKAGES,
            'dev'      => true,
        ]));
        $this->write($this->directory . '/bin/composer', implode("\n", [
            '#!/bin/sh',
            'if [ "$1" = --version ]; then echo "Composer version 2.10.3"; exit 0; fi',
            'if [ "$1" = dump-autoload ]; then',
            '    echo "$PWD $*" >> ' . escapeshellarg($this->directory . '/dump-arguments'),
            '    exit 0',
            'fi',
            'printf "%s" "$*" > ' . escapeshellarg($this->directory . '/composer-arguments'),
            'cp -R ' . escapeshellarg($this->directory . '/template/vendor') . ' vendor',
            'case "$*" in *--no-dev*) ;; *) cp ' . escapeshellarg($this->directory . '/installed-dev.json')
            . ' vendor/composer/installed.json ;; esac',
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
                'tokenizer',
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

    public function testPackagesAPharWithoutMetadataSecretsConfigurationOrOldOutput(): void
    {
        $this->settings([
            'phar' => true,
        ]);
        $output = $this->package($status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([
            'Package written to ' . $this->project . '/target/demo.phar.',
        ], $output);
        self::assertSame(
            'install --no-interaction --no-progress --ignore-platform-reqs --no-dev',
            file_get_contents($this->directory . '/composer-arguments'),
        );
        $this->assertDumpedInTheStagedCopy('--no-dev');
        self::assertStringStartsWith(
            "#!/usr/bin/env php\n",
            (string) file_get_contents($this->project . '/target/demo.phar'),
        );
        self::assertSame([
            'composer.json',
            'composer.lock',
            'src/App.php',
            'vendor/.cache/build.json',
            'vendor/autoload.php',
            'vendor/build-api.php',
            'vendor/composer/installed.json',
            'vendor/composer/installed.php',
        ], $this->entries($this->project . '/target/demo.phar'));

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->project . '/target/demo.phar'), $run, $status);

        self::assertSame(0, $status);
        self::assertSame([
            'running from demo.phar',
        ], $run);
    }

    public function testPackagesAStandaloneBinaryWithTheManagedMicroRuntime(): void
    {
        $this->settings([
            'dev' => true,
        ]);
        $output = $this->package($status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([
            'Package written to ' . $this->project . '/target/demo.',
        ], $output);
        self::assertSame(
            'install --no-interaction --no-progress --ignore-platform-reqs',
            file_get_contents($this->directory . '/composer-arguments'),
        );
        $this->assertDumpedInTheStagedCopy('--dev');
        $binary = (string) file_get_contents($this->project . '/target/demo');
        self::assertStringStartsWith('MICRO<?php', $binary);
    }

    public function testWritesThePackageToTheOutputOfTheDistributedConfigurationFile(): void
    {
        $this->write($this->project . '/skernel.json.dist', (string) json_encode([
            'php'      => $this->version,
            'composer' => $this->directory . '/bin/composer',
            'package'  => [
                'output' => 'dist/demo.phar',
                'phar'   => true,
            ],
        ]));

        $output = $this->package($status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([
            'Package written to ' . $this->project . '/dist/demo.phar.',
        ], $output);
        self::assertFileExists($this->project . '/dist/demo.phar');
        self::assertNotContains('skernel.json.dist', $this->entries($this->project . '/dist/demo.phar'));
    }

    public function testRejectsAMicroRuntimeThatIsNotFound(): void
    {
        $this->settings([
            'runtime' => 'build/micro.sfx',
        ]);

        $output = $this->package($status, '2>&1 >/dev/null');

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'Themicroruntime' . $this->project . '/build/micro.sfxwasnotfound.',
            (string) preg_replace('/\s+/', '', implode('', $output)),
        );
    }

    public function testPackagesAProjectWhoseDependenciesAreNotInstalled(): void
    {
        Directory::remove($this->project . '/vendor');
        $this->settings([
            'phar' => true,
        ]);

        $output = $this->package($status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([
            'Package written to ' . $this->project . '/target/demo.phar.',
        ], $output);
        self::assertDirectoryDoesNotExist($this->project . '/vendor');
    }

    public function testFindsAWorkingComposerWhenNoneIsConfigured(): void
    {
        $this->write($this->project . '/skernel.json', (string) json_encode([
            'php'     => $this->version,
            'package' => [
                'phar' => true,
            ],
        ]));

        exec(
            'PATH=' . escapeshellarg($this->directory . '/bin:/usr/bin:/bin')
            . ' HOME=' . escapeshellarg($this->directory)
            . ' SKERNEL_HOME=' . escapeshellarg($this->directory . '/home') . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/bootstrap.php')
            . ' package -d ' . escapeshellarg($this->project) . ' 2>/dev/null',
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

        $output = $this->package($status, '2>&1 >/dev/null');

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'The project in ' . $this->project . ' has no composer.lock; run composer install first.',
            (string) preg_replace('/\s+/', ' ', implode(' ', $output)),
        );
    }

    /**
     * Writes the project's `skernel.json` with the running PHP version, the stand-in Composer, and the given
     * `package` settings.
     *
     * @param array<string, mixed> $package
     */
    private function settings(array $package): void
    {
        $this->write($this->project . '/skernel.json', (string) json_encode([
            'php'      => $this->version,
            'composer' => $this->directory . '/bin/composer',
            'package'  => $package,
        ]));
    }

    /** @return list<string> */
    private function package(?int &$status, string $redirection = '2>/dev/null'): array
    {
        $command = 'SKERNEL_HOME=' . escapeshellarg($this->directory . '/home') . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/bootstrap.php')
            . ' package -d ' . escapeshellarg($this->project) . ' ' . $redirection;

        exec($command, $output, $status);

        return $output;
    }

    /** Asserts that the build regenerated the autoloader once, in the staged copy rather than the project */
    private function assertDumpedInTheStagedCopy(string $mode): void
    {
        $line = (string) file_get_contents($this->directory . '/dump-arguments');

        self::assertStringEndsWith('/project dump-autoload --no-interaction ' . $mode . "\n", $line);
        self::assertStringStartsNotWith($this->project . ' ', $line);
        self::assertSame(1, substr_count($line, "\n"));
    }

    private function vendor(string $directory): void
    {
        $this->write($directory . '/vendor/autoload.php', self::AUTOLOAD);
        BuildApiStandIn::write($directory, $directory . '/vendor');
        $this->write($directory . '/vendor/composer/installed.json', (string) json_encode([
            'packages' => self::PACKAGES,
            'dev'      => false,
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
