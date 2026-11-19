<?php

declare(strict_types=1);

namespace Src\Toolchain\Exception;

use Symfony\Component\Console\Exception\RuntimeException;

/**
 * Reports a download that the server answers does not exist, as opposed to one that cannot be reached.
 */
final class DownloadNotFoundException extends RuntimeException
{
}
