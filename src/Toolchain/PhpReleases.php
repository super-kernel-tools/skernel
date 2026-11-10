<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Composer\Semver\Semver;
use Symfony\Component\Console\Exception\RuntimeException;

use function is_array;
use function is_string;
use function sprintf;
use function substr_count;

/**
 * Resolves the exact PHP version that SKERNEL builds a managed runtime for.
 */
final readonly class PhpReleases
{
    public function __construct(private ReleaseIndex $index)
    {
    }

    /**
     * An exact version is kept, a major.minor version selects its latest release, and no version selects the latest
     * supported release that satisfies the project's PHP requirement.
     *
     * @param string|null $requested  A version such as 8.4 or 8.4.27
     * @param string|null $constraint The project's Composer requirement on php
     *
     * @throws RuntimeException When php.net lists no matching release or cannot be read
     */
    public function resolve(?string $requested, ?string $constraint): string
    {
        if (null !== $requested && 2 === substr_count($requested, '.')) {
            return $requested;
        }

        if (null !== $requested) {
            $release = $this->index->get($requested, 'index.php?json&version=' . $requested);

            if (!is_array($release) || !is_string($release['version'] ?? null)) {
                throw new RuntimeException(sprintf('php.net lists no PHP %s release.', $requested));
            }

            return $release['version'];
        }

        $versions = [];
        $active = $this->index->get('active', 'active.php');

        foreach (is_array($active) ? $active : [] as $branches) {
            foreach (is_array($branches) ? $branches : [] as $branch) {
                $version = is_array($branch) ? $branch['version'] ?? null : null;

                if (is_string($version) && (null === $constraint || Semver::satisfies($version, $constraint))) {
                    $versions[] = $version;
                }
            }
        }

        if ([] === $versions) {
            throw new RuntimeException(sprintf(
                'No supported PHP release satisfies the requirement %s; set "php" in skernel.json or pass --php.',
                $constraint,
            ));
        }

        return Semver::rsort($versions)[0];
    }
}
