<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Composer;

use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Model\PackageDeclaration;
use stdClass;

use function property_exists;
use function sprintf;
use function strtolower;

/**
 * Validates and reads the fields the generator uses from the root manifest or a lock entry.
 *
 * @internal
 */
final class DeclarationReader
{
    /**
     * @param bool $development Whether a lock entry comes from packages-dev; ignored for the root
     *
     * @throws GeneratorException When a used field is missing or malformed, or the package declares the unsupported
     *                            `target-dir` or `include-path`
     */
    public static function read(stdClass $document, bool $root, bool $development, string $context): PackageDeclaration
    {
        $name = JsonField::string($document, 'name', $context);

        if (null === $name) {
            throw new GeneratorException(sprintf('%s must declare a string name.', $context));
        }

        $name = strtolower($name);

        if (!PackageName::isPackage($name)) {
            throw new GeneratorException(sprintf('%s declares the invalid package name "%s".', $context, $name));
        }

        $version = JsonField::string($document, 'version', $context);

        if (!$root && null === $version) {
            throw new GeneratorException(sprintf('%s must declare a string version.', $context));
        }

        foreach ([
            'target-dir',
            'include-path',
        ] as $field) {
            if (property_exists($document, $field)) {
                throw new GeneratorException(sprintf(
                    '%s uses the Composer autoload feature "%s", which SuperKernel does not support.',
                    $context,
                    $field,
                ));
            }
        }

        $reference = null;

        if (!$root) {
            foreach ([
                'dist',
                'source',
            ] as $field) {
                $origin = JsonField::object($document, $field, $context);
                $reference ??= null === $origin
                    ? null
                    : JsonField::string($origin, 'reference', $context . ' ' . $field);
            }
        }

        return new PackageDeclaration(
            $name,
            $version,
            $reference,
            JsonField::string($document, 'type', $context) ?? 'library',
            $root,
            !$root && $development,
            JsonField::object($document, 'autoload', $context),
            $root ? JsonField::object($document, 'autoload-dev', $context) : null,
            RequirementParser::parse($document, $root, $context),
        );
    }
}
