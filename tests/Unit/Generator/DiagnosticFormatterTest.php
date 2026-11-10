<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use SKERNEL\Generator\Diagnostic\Diagnostic;
use SKERNEL\Generator\Diagnostic\DiagnosticFormatter;

final class DiagnosticFormatterTest extends TestCase
{
    public function testPrintsNothingWithoutDiagnostics(): void
    {
        self::assertSame([], DiagnosticFormatter::format([]));
    }

    public function testSortsByPackageAndClassAndEscapesControlCharacters(): void
    {
        self::assertSame([
            'SKERNEL skipped 3 classes whose attributes could not be collected:',
            '  acme/app App\\Alpha: fatal error: Declaration must be compatible',
            '  acme/app App\\Zeta: Error: Class "App\\Missing" not found',
            '  acme/lib Lib\\Broken: RuntimeException: line\\none\\ttab\\x07',
        ], DiagnosticFormatter::format([
            new Diagnostic(1, 'acme/lib', 'Lib\\Broken', 'RuntimeException', "line\none\ttab\x07"),
            new Diagnostic(2, 'acme/app', 'App\\Zeta', 'Error', 'Class "App\\Missing" not found'),
            new Diagnostic(3, 'acme/app', 'App\\Alpha', 'fatal error', 'Declaration must be compatible'),
        ]));
        self::assertSame(
            'SKERNEL skipped 1 class whose attributes could not be collected:',
            DiagnosticFormatter::format([
                new Diagnostic(1, 'acme/app', 'App\\Alpha', 'Error', 'Failed'),
            ])[0],
        );
    }
}
