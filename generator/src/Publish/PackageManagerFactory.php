<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Publish;

use InvalidArgumentException;
use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Model\PackageRecord;
use SuperKernel\Metadata\Manager\PackageManager;
use SuperKernel\Metadata\Model\EnvironmentRequirement;
use SuperKernel\Metadata\Model\Package;

use function array_shift;
use function sprintf;

/**
 * Builds the installed super-kernel/metadata package manager from the generator's package records.
 *
 * @internal
 */
final class PackageManagerFactory
{
    /**
     * @param non-empty-list<PackageRecord> $records The root first, then the installed lock packages
     *
     * @throws GeneratorException When super-kernel/metadata rejects a package
     */
    public static function create(array $records): PackageManager
    {
        $packages = [];
        $classes = [];

        foreach ($records as $record) {
            $declaration = $record->declaration;
            $requirements = [];

            foreach ($declaration->requirements as $requirement) {
                $requirements[] = new EnvironmentRequirement(
                    $requirement->name,
                    $requirement->constraint,
                    $declaration->name,
                    $requirement->development,
                );
            }

            try {
                $packages[] = new Package(
                    $declaration->name,
                    $declaration->version,
                    $declaration->reference,
                    $declaration->composerType,
                    $record->locatedPath,
                    $requirements,
                );
            } catch (InvalidArgumentException $exception) {
                throw new GeneratorException(sprintf(
                    'The package "%s" cannot be recorded: %s',
                    $declaration->name,
                    $exception->getMessage(),
                ), previous: $exception);
            }

            $classes[$declaration->name] = $record->classMap->classes;
        }

        $root = array_shift($packages);

        return new PackageManager($root, $packages, $classes);
    }
}
