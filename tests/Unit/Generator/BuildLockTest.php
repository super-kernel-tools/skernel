<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Generator;

use SKERNEL\Generator\Entry\BuildLock;
use Symfony\Component\Process\Process;

use function var_export;

use const PHP_BINARY;

final class BuildLockTest extends GeneratorTestCase
{
    public function testExcludesAnotherProcessUntilReleased(): void
    {
        $lock = new BuildLock($this->directory . '/vendor');

        self::assertFileExists($this->directory . '/vendor/.cache/skernel.lock');
        self::assertFalse($this->lockedElsewhere());

        $lock->release();
        $lock->release();

        self::assertTrue($this->lockedElsewhere());
    }

    /**
     * Tries to take the lock without waiting in a separate PHP process.
     */
    private function lockedElsewhere(): bool
    {
        $process = new Process([
            PHP_BINARY,
            '-r',
            'exit(flock(fopen(' . var_export($this->directory . '/vendor/.cache/skernel.lock', true)
            . ', "c"), LOCK_EX | LOCK_NB) ? 0 : 1);',
        ]);

        return 0 === $process->run();
    }
}
