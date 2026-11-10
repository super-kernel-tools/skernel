<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Autoload;

use SKERNEL\Generator\Constants\ScanRuleKind;
use SKERNEL\Generator\Constants\ScanRuleSource;

/**
 * One package-relative path that an autoload declaration asks to scan for classes.
 *
 * @internal
 */
final readonly class ScanRule
{
    /**
     * @param string|null $namespacePrefix The PSR-0 or PSR-4 prefix; null for a classmap rule
     */
    public function __construct(
        public string         $relativePath,
        public ScanRuleSource $source,
        public ScanRuleKind   $kind,
        public ?string        $namespacePrefix,
    )
    {
    }
}
