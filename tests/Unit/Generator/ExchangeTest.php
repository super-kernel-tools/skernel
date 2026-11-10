<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Generator;

use SKERNEL\Generator\Constants\ReportStatus;
use SKERNEL\Generator\Diagnostic\Diagnostic;
use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Exception\ProtocolMismatchException;
use SKERNEL\Generator\Exchange\Report;
use SKERNEL\Generator\Exchange\Request;
use SKERNEL\Generator\GenerationResult;

final class ExchangeTest extends GeneratorTestCase
{
    public function testRequestsRoundTrip(): void
    {
        $path = $this->directory . '/request.json';
        new Request('/project', '/project/vendor', '1.0.0', [
            'App\\Broken' => 'Cannot redeclare',
        ])->write($path);
        $request = Request::read($path);

        self::assertSame('/project', $request->projectDirectory);
        self::assertSame('/project/vendor', $request->vendorDirectory);
        self::assertSame('1.0.0', $request->skernelVersion);
        self::assertSame([
            'App\\Broken' => 'Cannot redeclare',
        ], $request->fatalClasses);

        $this->write('invalid.json', '{"project": "/project"}');
        $this->expectException(GeneratorException::class);
        Request::read($this->directory . '/invalid.json');
    }

    public function testReportsRoundTripAndAMissingReportReadsAsNone(): void
    {
        $path = $this->directory . '/report.json';
        Report::built(new GenerationResult(3, 12, [
            new Diagnostic(1, 'acme/app', 'App\\Broken', 'Error', "Multi\nline"),
        ]))->write($path);
        $report = Report::read($path);

        self::assertNotNull($report);
        self::assertSame(ReportStatus::Built, $report->status);
        self::assertSame(3, $report->packages);
        self::assertSame(12, $report->classes);
        self::assertEquals([
            new Diagnostic(1, 'acme/app', 'App\\Broken', 'Error', "Multi\nline"),
        ], $report->diagnostics);

        new Report(ReportStatus::ClassFatal, 'Fatal', 'acme/app', 'App\\Broken')->write($path);
        $report = Report::read($path);
        self::assertSame(ReportStatus::ClassFatal, $report?->status);
        self::assertSame('acme/app', $report->package);
        self::assertSame('App\\Broken', $report->class);
        self::assertSame('Fatal', $report->message);

        self::assertNull(Report::read($this->directory . '/missing.json'));
        $this->write('empty.json', '');
        self::assertNull(Report::read($this->directory . '/empty.json'));
        $this->write('invalid.json', '{"status": "unknown"}');
        $this->expectException(GeneratorException::class);
        Report::read($this->directory . '/invalid.json');
    }

    public function testProtocolMismatchesNameTheSideToUpgrade(): void
    {
        self::assertSame(
            'The project does not install super-kernel/metadata, so SKERNEL cannot publish its build artifacts. '
            . 'Require SuperKernel 1.0.0-alpha.2 or later.',
            new ProtocolMismatchException(null, 1)->getMessage(),
        );
        self::assertSame(
            'The project\'s super-kernel/metadata uses build protocol 2, but this SKERNEL generates protocol 1. '
            . 'Upgrade SKERNEL, for example with "skernel self-update".',
            new ProtocolMismatchException(2, 1)->getMessage(),
        );
        self::assertSame(
            'The project\'s super-kernel/metadata uses build protocol 1, but this SKERNEL generates protocol 2. '
            . 'Upgrade the project\'s SuperKernel packages, or use a SKERNEL release that generates protocol 1.',
            new ProtocolMismatchException(1, 2)->getMessage(),
        );
    }
}
