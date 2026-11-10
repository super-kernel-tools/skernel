<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Attribute;

/**
 * The class being scanned right now, so that a process that a class ends can still say which class it was.
 *
 * @internal
 */
final class ScanProgress
{
    public ?string $package = null;

    /** @var class-string|null */
    public ?string $class = null;
}
