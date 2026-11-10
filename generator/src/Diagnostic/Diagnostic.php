<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Diagnostic;

/**
 * A class whose attributes could not be collected, and why.
 *
 * @internal
 */
final readonly class Diagnostic
{
    /**
     * @param positive-int $sequence Position among the build's diagnostics, in scan order
     * @param string       $type     The class of the throwable, or `fatal error` when loading the class ended the
     *                               process
     */
    public function __construct(
        public int    $sequence,
        public string $package,
        public string $class,
        public string $type,
        public string $message,
    )
    {
    }
}
