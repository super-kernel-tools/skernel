<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Project;

use FilesystemIterator;
use JsonException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Src\Project\ProjectLocator;
use Symfony\Component\Console\Exception\RuntimeException;

use function chdir;
use function file_put_contents;
use function getcwd;
use function json_encode;
use function mkdir;
use function realpath;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;

final class ProjectLocatorTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/' . uniqid('skernel-locator-', true);
        mkdir($this->workspace);
        $this->workspace = (string) realpath($this->workspace);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspace, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->workspace);
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testLocatesTheGivenDirectoryWithTheDefaultVendorDirectory(): void
    {
        $this->writeProject([], 'vendor');

        $project = new ProjectLocator()->locate($this->workspace);

        self::assertSame($this->workspace, $project->directory);
        self::assertSame($this->workspace . '/vendor', $project->vendorDirectory);
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testHonoursTheConfiguredVendorDirectory(): void
    {
        $this->writeProject([
            'config' => [
                'vendor-dir' => 'lib/dependencies/',
            ],
        ], 'lib/dependencies');

        $project = new ProjectLocator()->locate($this->workspace);

        self::assertSame($this->workspace . '/lib/dependencies', $project->vendorDirectory);
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testLocatesTheCurrentDirectoryWhenNoneIsGiven(): void
    {
        $this->writeProject([], 'vendor');
        $previous = (string) getcwd();
        chdir($this->workspace);

        try {
            $project = new ProjectLocator()->locate(null);
        } finally {
            chdir($previous);
        }

        self::assertSame($this->workspace, $project->directory);
    }

    public function testRejectsAMissingDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The project directory ' . $this->workspace . '/missing does not exist.');

        new ProjectLocator()->locate($this->workspace . '/missing');
    }

    public function testRejectsADirectoryWithoutComposerJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('No composer.json found in ' . $this->workspace . '.');

        new ProjectLocator()->locate($this->workspace);
    }

    public function testRejectsAComposerJsonThatIsNotValidJson(): void
    {
        file_put_contents($this->workspace . '/composer.json', '{');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($this->workspace . '/composer.json does not contain valid JSON.');

        new ProjectLocator()->locate($this->workspace);
    }

    public function testRejectsAProjectWithoutInstalledDependencies(): void
    {
        file_put_contents($this->workspace . '/composer.json', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'The project in ' . $this->workspace . ' has no installed dependencies; run composer install first.',
        );

        new ProjectLocator()->locate($this->workspace);
    }

    public function testFindsAProjectWhoseDependenciesAreNotInstalled(): void
    {
        file_put_contents($this->workspace . '/composer.json', '{"name": "acme/demo", "require": {"php": ">=8.4"}}');

        $project = new ProjectLocator()->find($this->workspace);

        self::assertSame($this->workspace, $project->directory);
        self::assertSame($this->workspace . '/vendor', $project->vendorDirectory);
        self::assertSame('acme/demo', $project->name);
        self::assertSame('>=8.4', $project->phpRequirement);
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testRejectsAProjectThatDoesNotInstallSuperKernel(): void
    {
        $this->writeProject([], 'vendor', [
            'super-kernel/context',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'The project in ' . $this->workspace . ' is not a SuperKernel application: super-kernel/di is not installed.',
        );

        new ProjectLocator()->locate($this->workspace);
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string>         $packages
     *
     * @throws JsonException When a fixture cannot be encoded
     */
    private function writeProject(
        array  $manifest,
        string $vendorDirectory,
        array  $packages = [
            'super-kernel/context',
            'super-kernel/di',
        ],
    ): void
    {
        $installed = [];

        foreach ($packages as $name) {
            $installed[] = [
                'name' => $name,
            ];
        }

        $vendor = $this->workspace . '/' . $vendorDirectory;
        mkdir($vendor . '/composer', 0o777, true);
        file_put_contents($this->workspace . '/composer.json', [] === $manifest ? '{}' : json_encode(
            $manifest,
            JSON_THROW_ON_ERROR,
        ));
        file_put_contents($vendor . '/autoload.php', '<?php');
        file_put_contents($vendor . '/composer/installed.json', json_encode([
            'packages' => $installed,
        ], JSON_THROW_ON_ERROR));
    }
}
