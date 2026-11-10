<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Diagnostic;

use function count;
use function ord;
use function preg_replace_callback;
use function sprintf;
use function strcmp;
use function usort;

/**
 * Formats skipped classes as the lines `build` prints after a successful build.
 *
 * @internal
 */
final class DiagnosticFormatter
{
    /**
     * @param list<Diagnostic> $diagnostics
     *
     * @return list<string> A header and one line per class sorted by package and class, or nothing for no diagnostic;
     *                      control characters are escaped
     */
    public static function format(array $diagnostics): array
    {
        if ([] === $diagnostics) {
            return [];
        }

        usort(
            $diagnostics,
            static fn(Diagnostic $left, Diagnostic $right): int => strcmp($left->package, $right->package)
                ?: strcmp($left->class, $right->class),
        );
        $escape = static fn(string $value): string => preg_replace_callback(
            '/[\x00-\x1f\x7f]/',
            static fn(array $match): string => match ($match[0]) {
                "\n"    => '\\n',
                "\r"    => '\\r',
                "\t"    => '\\t',
                default => sprintf('\\x%02X', ord($match[0])),
            },
            $value,
        );
        $lines = [
            1 === count($diagnostics)
                ? 'SKERNEL skipped 1 class whose attributes could not be collected:'
                : sprintf('SKERNEL skipped %d classes whose attributes could not be collected:', count($diagnostics)),
        ];

        foreach ($diagnostics as $diagnostic) {
            $lines[] = sprintf(
                '  %s %s: %s: %s',
                $escape($diagnostic->package),
                $escape($diagnostic->class),
                $escape($diagnostic->type),
                $escape($diagnostic->message),
            );
        }

        return $lines;
    }
}
