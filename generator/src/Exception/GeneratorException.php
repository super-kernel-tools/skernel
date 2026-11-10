<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Exception;

use RuntimeException;

/**
 * Reports a project that the generator cannot read, scan, or publish, with a message meant for the developer.
 */
class GeneratorException extends RuntimeException
{
}
