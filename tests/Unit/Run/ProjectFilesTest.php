<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Run;

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
            'composer.lock',
            'config/app.php',
            'src/App.php',
        ] as $file) {
            $this->write($file);
        }

        foreach ([
            '.git/hook.php',
            '.idea/workspace.php',
            'composer.json',
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
            $this->home . '/composer.lock',
            $this->home . '/config/app.php',
            $this->home . '/src/App.php',
        ], array_keys(ProjectFiles::snapshot($this->project())));
    }

    public function testAChangedSizeTimeOrFileChangesTheSnapshot(): void
    {
        $this->write('src/App.php');
        $project = $this->project();
        $snapshot = ProjectFiles::snapshot($project);
        self::assertSame($snapshot, ProjectFiles::snapshot($project));

        self::assertNotFalse(file_put_contents($this->home . '/src/App.php', 'changed'));
        $changed = ProjectFiles::snapshot($project);
        self::assertNotSame($snapshot, $changed);

        self::assertTrue(touch($this->home . '/src/App.php', 1));
        $touched = ProjectFiles::snapshot($project);
        self::assertNotSame($changed, $touched);

        $this->write('src/Other.php');
        self::assertNotSame($touched, ProjectFiles::snapshot($project));
    }

    public function testLeavesOutAFileThatCannotBeRead(): void
    {
        $this->write('src/App.php');
        self::assertTrue(symlink($this->home . '/src/missing.php', $this->home . '/src/Broken.php'));

        self::assertSame([
            $this->home . '/src/App.php',
        ], array_keys(ProjectFiles::snapshot($this->project())));
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
