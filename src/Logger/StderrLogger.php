<?php

declare(strict_types=1);

namespace Src\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\Logger\Factory\LoggerFactory;
use SuperKernel\Logger\Formatter\ConsoleFormatter;

use function is_string;
use function str_starts_with;

/**
 * Writes SKERNEL and framework log records of WARNING and above to STDERR, keeping STDOUT for command output.
 *
 * The framework's report of the classes that a scan of SKERNEL itself skipped is left out, because it says nothing that
 * a SKERNEL user can act on. A scanned project still reports them through its own logger.
 */
#[Provider(StdoutLoggerInterface::class)]
final readonly class StderrLogger implements StdoutLoggerInterface
{
    use LoggerTrait;

    private const string SCAN_DIAGNOSTIC_EVENT = 'annotation_scan.';

    private LoggerInterface $logger;

    public function __construct(LoggerFactory $factory)
    {
        $this->logger = $factory->create(
            channel: 'skernel',
            stream: 'php://stderr',
            minimumLevel: LogLevel::WARNING,
            formatter: new ConsoleFormatter(),
        );
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $event = $context['event'] ?? null;

        if (is_string($event) && str_starts_with($event, self::SCAN_DIAGNOSTIC_EVENT)) {
            return;
        }

        $this->logger->log($level, $message, $context);
    }
}
