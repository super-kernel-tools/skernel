<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Logger;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Src\Logger\StderrLogger;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\StdoutLoggerInterface;

final class StderrLoggerTest extends TestCase
{
    public function testReplacesTheNamedStdoutLogger(): void
    {
        $reflection = new ReflectionClass(StderrLogger::class);
        $attributes = $reflection->getAttributes(Provider::class);

        self::assertTrue($reflection->implementsInterface(StdoutLoggerInterface::class));
        self::assertCount(1, $attributes);
        self::assertSame([
            StdoutLoggerInterface::class,
        ], $attributes[0]->getArguments());
    }
}
