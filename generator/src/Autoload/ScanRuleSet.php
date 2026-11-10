<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Autoload;

use function array_any;
use function array_filter;
use function array_merge;
use function array_unique;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function ltrim;
use function preg_match;
use function preg_quote;
use function sort;
use function str_contains;
use function str_replace;
use function strtr;
use function trim;

use const SORT_STRING;

/**
 * The scan rules and `exclude-from-classmap` patterns of one package.
 *
 * @internal
 */
final readonly class ScanRuleSet
{
    /** @var list<string> */
    public array $exclusions;

    /**
     * @param list<ScanRule> $inclusions
     * @param list<string>   $exclusions Normalized patterns; a leading `/` anchors one at the package root
     */
    public function __construct(public array $inclusions = [], array $exclusions = [])
    {
        $exclusions = array_values(array_unique($exclusions));
        sort($exclusions, SORT_STRING);
        $this->exclusions = $exclusions;
    }

    public function merge(self $other): self
    {
        return new self(
            array_merge($this->inclusions, $other->inclusions),
            array_merge($this->exclusions, $other->exclusions),
        );
    }

    public function isExcluded(string $packageRelativePath): bool
    {
        if (str_contains($packageRelativePath, "\0")) {
            return false;
        }

        $segments = explode('/', ltrim(str_replace('\\', '/', $packageRelativePath), '/'));

        if (in_array('..', $segments, true)) {
            return false;
        }

        $path = implode('/', array_values(array_filter(
            $segments,
            static fn(string $segment): bool => '' !== $segment && '.' !== $segment,
        )));

        return array_any(
            $this->exclusions,
            static function (string $pattern) use ($path): bool {
                $regex = strtr(preg_quote(trim($pattern, '/'), '~'), [
                    '\\*\\*' => '.+?',
                    '\\*'    => '[^/]+?',
                ]);

                return 1 === preg_match('~\\A' . $regex . '(?:\\z|/)~', $path);
            },
        );
    }
}
