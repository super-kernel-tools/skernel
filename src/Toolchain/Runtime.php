<?php

declare(strict_types=1);

namespace Src\Toolchain;

/**
 * A managed static PHP build: its CLI binary and its micro runtime for standalone binaries.
 */
final readonly class Runtime
{
    public function __construct(public string $version, public string $php, public string $micro)
    {
    }
}
