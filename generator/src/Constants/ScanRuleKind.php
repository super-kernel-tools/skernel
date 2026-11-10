<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Constants;

/**
 * What a scan rule's package-relative path names.
 */
enum ScanRuleKind: string
{
    case Directory = 'directory';

    case File = 'file';

    /** A safe Composer classmap `*` glob with at least one existing match. */
    case Glob = 'glob';
}
