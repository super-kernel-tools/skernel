<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Toolchain;

use Src\Toolchain\Home;

use function putenv;

final class HomeTest extends ToolchainTestCase
{
    public function testTheSKERNELHomeVariableOverridesTheUsersHome(): void
    {
        self::assertSame($this->home, new Home()->directory());
    }

    public function testDefaultsToADirectoryInTheUsersHome(): void
    {
        putenv('SKERNEL_HOME');
        putenv('HOME=/home/someone/');

        try {
            self::assertSame('/home/someone/.skernel', new Home()->directory());
        } finally {
            putenv('HOME=' . $_SERVER['HOME']);
        }
    }
}
