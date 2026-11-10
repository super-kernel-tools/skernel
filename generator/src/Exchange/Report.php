<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Exchange;

use JsonException;
use SKERNEL\Generator\Constants\ReportStatus;
use SKERNEL\Generator\Diagnostic\Diagnostic;
use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\GenerationResult;

use function array_map;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * How a generator child process ended, written as a JSON file for SKERNEL to read.
 *
 * @internal
 */
final readonly class Report
{
    /**
     * @param int|null         $projectProtocol For a protocol mismatch: the project's protocol, null without metadata
     * @param list<Diagnostic> $diagnostics     For a build: the skipped classes in scan order
     */
    public function __construct(
        public ReportStatus $status,
        public ?string      $message = null,
        public ?string      $package = null,
        public ?string      $class = null,
        public ?int         $projectProtocol = null,
        public int          $packages = 0,
        public int          $classes = 0,
        public array        $diagnostics = [],
    )
    {
    }

    public static function built(GenerationResult $result): self
    {
        return new self(
            ReportStatus::Built,
            packages: $result->packages,
            classes: $result->classes,
            diagnostics: $result->diagnostics,
        );
    }

    /**
     * @throws GeneratorException When the file cannot be written
     */
    public function write(string $path): void
    {
        try {
            $json = json_encode([
                'status'      => $this->status->value,
                'message'     => $this->message,
                'package'     => $this->package,
                'class'       => $this->class,
                'project'     => $this->projectProtocol,
                'packages'    => $this->packages,
                'classes'     => $this->classes,
                'diagnostics' => array_map(static fn(Diagnostic $diagnostic): array => [
                    'sequence' => $diagnostic->sequence,
                    'package'  => $diagnostic->package,
                    'class'    => $diagnostic->class,
                    'type'     => $diagnostic->type,
                    'message'  => $diagnostic->message,
                ], $this->diagnostics),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new GeneratorException('Unable to encode the generator report.', previous: $exception);
        }

        if (false === @file_put_contents($path, $json)) {
            throw new GeneratorException(sprintf('Unable to write the generator report %s.', $path));
        }
    }

    /**
     * @return self|null The report, or null when the child wrote none
     *
     * @throws GeneratorException When the file holds something other than a report
     */
    public static function read(string $path): ?self
    {
        $contents = @file_get_contents($path);

        if (false === $contents || '' === $contents) {
            return null;
        }

        try {
            $report = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GeneratorException(sprintf('Invalid generator report %s.', $path), previous: $exception);
        }

        $status = is_array($report) && is_string($report['status'] ?? null)
            ? ReportStatus::tryFrom($report['status'])
            : null;

        if (
            null === $status
            || !is_int($report['packages'] ?? null)
            || !is_int($report['classes'] ?? null)
            || !is_array($report['diagnostics'] ?? null)
        ) {
            throw new GeneratorException(sprintf('Invalid generator report %s.', $path));
        }

        $diagnostics = [];

        foreach ($report['diagnostics'] as $diagnostic) {
            if (
                !is_array($diagnostic)
                || !is_int($diagnostic['sequence'] ?? null)
                || 1 > $diagnostic['sequence']
                || !is_string($diagnostic['package'] ?? null)
                || !is_string($diagnostic['class'] ?? null)
                || !is_string($diagnostic['type'] ?? null)
                || !is_string($diagnostic['message'] ?? null)
            ) {
                throw new GeneratorException(sprintf('Invalid diagnostic in the generator report %s.', $path));
            }

            $diagnostics[] = new Diagnostic(
                $diagnostic['sequence'],
                $diagnostic['package'],
                $diagnostic['class'],
                $diagnostic['type'],
                $diagnostic['message'],
            );
        }

        return new self(
            $status,
            is_string($report['message'] ?? null) ? $report['message'] : null,
            is_string($report['package'] ?? null) ? $report['package'] : null,
            is_string($report['class'] ?? null) ? $report['class'] : null,
            is_int($report['project'] ?? null) ? $report['project'] : null,
            $report['packages'],
            $report['classes'],
            $diagnostics,
        );
    }
}
