<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Composer;

use SKERNEL\Generator\Exception\GeneratorException;
use Throwable;

use function array_keys;
use function is_array;
use function is_bool;
use function is_file;
use function sprintf;

/**
 * Reads which lock packages Composer actually installed from `vendor/composer/installed.php`.
 *
 * @internal
 */
final readonly class InstalledState
{
    /**
     * @param bool                $development Whether development dependencies were installed
     * @param array<string, true> $packages    Installed package names
     */
    public function __construct(public bool $development, public array $packages)
    {
    }

    /**
     * @throws GeneratorException When the installation state is missing, unreadable, or invalid
     */
    public static function read(string $vendorDirectory): self
    {
        $path = $vendorDirectory . '/composer/installed.php';

        if (!is_file($path)) {
            throw new GeneratorException(sprintf(
                'Composer installation state is unavailable: %s. Install the project\'s dependencies first.',
                $path,
            ));
        }

        try {
            $state = (static fn(): mixed => require $path)();
        } catch (Throwable $exception) {
            throw new GeneratorException(sprintf('Unable to read %s.', $path), previous: $exception);
        }

        if (
            !is_array($state)
            || !is_array($state['root'] ?? null)
            || !is_bool($state['root']['dev'] ?? null)
            || !is_array($state['versions'] ?? null)
        ) {
            throw new GeneratorException(sprintf('Invalid Composer installation state: %s.', $path));
        }

        $packages = [];

        foreach (array_keys($state['versions']) as $name) {
            $packages[(string) $name] = true;
        }

        return new self($state['root']['dev'], $packages);
    }

    /**
     * Reports whether a lock entry is installed: production entries always are, development ones only with dev
     * dependencies or when Composer lists them.
     */
    public function contains(string $name, bool $development): bool
    {
        return !$development || $this->development || isset($this->packages[$name]);
    }
}
