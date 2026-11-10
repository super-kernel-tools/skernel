<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Constants;

/**
 * The Composer autoload mechanism that declared a scan rule.
 */
enum ScanRuleSource: string
{
    case Psr0 = 'psr-0';

    case Psr4 = 'psr-4';

    case Classmap = 'classmap';
}
