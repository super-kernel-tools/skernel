<?php

declare(strict_types=1);

namespace SKERNEL\Generator;

use SKERNEL\Generator\Diagnostic\Diagnostic;

/**
 * What a successful generation published.
 *
 * @internal
 */
final readonly class GenerationResult
{
    /**
     * @param int              $packages    The root and the installed lock packages
     * @param int              $classes     Classes in all class maps
     * @param list<Diagnostic> $diagnostics Skipped classes in scan order
     */
    public function __construct(public int $packages, public int $classes, public array $diagnostics)
    {
    }
}
