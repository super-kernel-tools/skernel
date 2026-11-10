<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Model;

/**
 * A platform requirement, such as `php` or `ext-swoole`, that a package declares.
 *
 * @internal
 */
final readonly class Requirement
{
    /**
     * @param bool $development Whether the root declares it in require-dev
     */
    public function __construct(public string $name, public string $constraint, public bool $development)
    {
    }
}
