<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Generator;

use JsonException;
use SKERNEL\Generator\Constants\ReportStatus;
use SKERNEL\Generator\Exchange\Report;
use SKERNEL\Generator\Exchange\Request;
use Symfony\Component\Process\Process;

use function dirname;
use function sprintf;
use function var_export;

use const PHP_BINARY;

/**
 * Runs the generator entry as `build` does, against generated projects whose vendor directory only fakes the
 * autoloader, so the paths that end before publication need no installed super-kernel/metadata.
 */
final class ChildProcessTest extends GeneratorTestCase
{
    /** @throws JsonException When a fixture cannot be encoded */
    public function testReportsAProjectWithoutMetadata(): void
    {
        $this->project(null);
        $report = $this->generate();

        self::assertSame(ReportStatus::ProtocolMismatch, $report->status);
        self::assertNull($report->projectProtocol);
        self::assertStringContainsString('does not install super-kernel/metadata', (string) $report->message);
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testReportsAnotherProtocol(): void
    {
        $this->project(2);
        $report = $this->generate();

        self::assertSame(ReportStatus::ProtocolMismatch, $report->status);
        self::assertSame(2, $report->projectProtocol);
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testReportsTheClassThatEndedTheProcessAndWritesNothing(): void
    {
        $this->project(1);
        $this->write('src/Broken.php', <<<'PHP'
            <?php

            namespace App;

            #[\Attribute]
            final class Broken implements \Countable
            {
            }
            PHP);
        $report = $this->generate();

        self::assertSame(ReportStatus::ClassFatal, $report->status);
        self::assertSame('acme/app', $report->package);
        self::assertSame('App\\Broken', $report->class);
        self::assertStringContainsString('abstract method', (string) $report->message);
        self::assertDirectoryDoesNotExist($this->directory . '/vendor/.cache');
    }

    public function testRejectsAWrongInvocation(): void
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 3) . '/generator/generate.php',
        ]);

        self::assertSame(64, $process->run());
        self::assertStringContainsString('Usage: generate.php', $process->getErrorOutput());
    }

    private function generate(): Report
    {
        $request = $this->directory . '/request.json';
        $reportPath = $this->directory . '/report.json';
        new Request($this->directory, $this->directory . '/vendor', '1.0.0')->write($request);
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 3) . '/generator/generate.php',
            $request,
            $reportPath,
        ], $this->directory);

        $process->run();
        $report = Report::read($reportPath);
        self::assertNotNull($report, $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());

        return $report;
    }

    /**
     * Writes a project whose fake autoloader maps `App\` to `src/` and, given a protocol, declares it with stand-ins
     * for the metadata models that the generator builds before it scans.
     *
     * @throws JsonException When a fixture cannot be encoded
     */
    private function project(?int $protocol): void
    {
        $this->writeJson('composer.json', [
            'name'     => 'acme/app',
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ]);
        $this->writeJson('composer.lock', [
            'packages'     => [],
            'packages-dev' => [],
        ]);
        $this->write('vendor/composer/installed.php', "<?php return ['root' => ['dev' => true], 'versions' => []];");
        $this->write('vendor/autoload.php', sprintf(<<<'PHP'
            <?php

            spl_autoload_register(static function (string $class): void {
                if (str_starts_with($class, 'App\\')) {
                    require %s . str_replace('\\', '/', substr($class, 4)) . '.php';
                }
            });

            if (null !== %s) {
                eval('namespace SuperKernel\Metadata\Constants; final class Protocol { public const int VERSION = '
                    . %2$s . '; }');
                eval('namespace SuperKernel\Metadata\Model; final class Package { public function __construct(...$a) {} }');
                eval('namespace SuperKernel\Metadata\Manager; final class PackageManager {'
                    . ' public function __construct(...$a) {} }');
            }
            PHP, var_export($this->directory . '/src/', true), var_export($protocol, true)));
    }
}
