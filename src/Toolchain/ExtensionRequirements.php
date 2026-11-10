<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Composer\Semver\Semver;

use function array_all;
use function array_any;
use function array_filter;
use function array_map;
use function array_values;
use function implode;

/**
 * The PHP extensions that a project's installation requires, by their static-php-cli names, and the versions of them
 * that it accepts.
 */
final readonly class ExtensionRequirements
{
    /**
     * @param list<string>                $names       Sorted extension names
     * @param array<string, list<string>> $constraints Composer constraints that a version of an extension must satisfy
     * @param array<string, list<string>> $conflicts   Composer constraints that a version of an extension must not
     *                                                 satisfy
     */
    public function __construct(public array $names, public array $constraints = [], public array $conflicts = [])
    {
    }

    /** Whether Composer would accept this version of the extension for the installation */
    public function allows(string $name, string $version): bool
    {
        $matches = static fn(string $constraint): bool => Semver::satisfies($version, $constraint);

        return array_all($this->constraints[$name] ?? [], $matches)
            && !array_any($this->conflicts[$name] ?? [], $matches);
    }

    /** @return list<string> The required extensions of which the installation accepts only some versions */
    public function versioned(): array
    {
        return array_values(array_filter(
            $this->names,
            fn(string $name): bool => isset($this->constraints[$name]) || isset($this->conflicts[$name]),
        ));
    }

    /** Describes the versions of the extension that the installation accepts */
    public function describe(string $name): string
    {
        return implode(', ', [
            ...$this->constraints[$name] ?? [],
            ...array_map(static fn(string $conflict): string => 'not ' . $conflict, $this->conflicts[$name] ?? []),
        ]);
    }
}
