<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Run;

use Src\Configuration\RunConfiguration;
use Src\Project\Project;
use Src\Run\ProjectFiles;
use Src\Tests\Unit\Toolchain\ToolchainTestCase;

use function array_keys;
use function dirname;
use function file_put_contents;
use function mkdir;
use function symlink;
use function touch;

final class ProjectFilesTest extends ToolchainTestCase
{
    public function testWatchesSourcesAndConfigurationButNotWhatTheApplicationWrites(): void
    {
        foreach ([
            '.env',
            '.env.local',
            'composer.json',
            'composer.lock',
            'config/app.php',
            'src/App.php',
        ] as $file) {
            $this->write($file);
        }

        foreach ([
            '.git/hook.php',
            '.idea/workspace.php',
            'runtime/app.log',
            'target/demo.php',
            'vendor/autoload.php',
            'vendor/.cache/classes.php',
        ] as $file) {
            $this->write($file);
        }

        self::assertSame([
            $this->home . '/.env',
            $this->home . '/.env.local',
            $this->home . '/composer.json',
            $this->home . '/composer.lock',
            $this->home . '/config/app.php',
            $this->home . '/src/App.php',
        ], array_keys(ProjectFiles::snapshot($this->project(), $this->defaults())));
    }

    public function testWatchesOnlyTheFilesWhoseNameMatchesAPattern(): void
    {
        foreach ([
            'composer.json',
            'config/app.yaml',
            'config/app.yml',
            'src/App.php',
            'vendor/package.yaml',
        ] as $file) {
            $this->write($file);
        }

        self::assertSame([
            $this->home . '/config/app.yaml',
            $this->home . '/config/app.yml',
            $this->home . '/src/App.php',
        ], array_keys(ProjectFiles::snapshot($this->project(), [
            '*.php',
            '*.y[a]ml',
            'app.y?l',
        ])));
    }

    public function testAChangedSizeTimeOrFileChangesTheSnapshot(): void
    {
        $this->write('src/App.php');
        $project = $this->project();
        $snapshot = ProjectFiles::snapshot($project, $this->defaults());
        self::assertSame($snapshot, ProjectFiles::snapshot($project, $this->defaults()));

        self::assertNotFalse(file_put_contents($this->home . '/src/App.php', 'changed'));
        $changed = ProjectFiles::snapshot($project, $this->defaults());
        self::assertNotSame($snapshot, $changed);

        self::assertTrue(touch($this->home . '/src/App.php', 1));
        $touched = ProjectFiles::snapshot($project, $this->defaults());
        self::assertNotSame($changed, $touched);

        $this->write('src/Other.php');
        self::assertNotSame($touched, ProjectFiles::snapshot($project, $this->defaults()));
    }

    public function testLeavesOutAFileThatCannotBeRead(): void
    {
        $this->write('src/App.php');
        self::assertTrue(symlink($this->home . '/src/missing.php', $this->home . '/src/Broken.php'));

        self::assertSame([
            $this->home . '/src/App.php',
        ], array_keys(ProjectFiles::snapshot($this->project(), $this->defaults())));
    }

    /** @return non-empty-list<string> */
    private function defaults(): array
    {
        return new RunConfiguration()->watchFiles;
    }

    private function project(): Project
    {
        return new Project($this->home, $this->home . '/vendor', 'acme/app', null);
    }

    private function write(string $file): void
    {
        @mkdir(dirname($this->home . '/' . $file), 0o700, true);
        self::assertNotFalse(file_put_contents($this->home . '/' . $file, ''));
    }
}
