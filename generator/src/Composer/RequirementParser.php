<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Composer;

use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Model\Requirement;
use stdClass;

use function get_object_vars;
use function is_string;
use function sprintf;
use function strcmp;
use function usort;

/**
 * Validates Composer requirement sections and keeps their platform requirements.
 *
 * @internal
 */
final class RequirementParser
{
    /**
     * Reads `require`, and for the root also `require-dev`; a dependency's `require-dev` is root-only in Composer and
     * is ignored.
     *
     * @return list<Requirement> Platform requirements sorted by name
     *
     * @throws GeneratorException When a section is not an object, or a name, constraint, or duplicate is invalid
     */
    public static function parse(stdClass $document, bool $root, string $context): array
    {
        $requirements = [];
        $seen = [];
        $sections = $root
            ? [
                'require'     => false,
                'require-dev' => true,
            ]
            : [
                'require' => false,
            ];

        foreach ($sections as $field => $development) {
            $section = JsonField::object($document, $field, $context) ?? new stdClass();

            foreach (get_object_vars($section) as $name => $constraint) {
                $name = (string) $name;

                if (!PackageName::isPackage($name) && !PackageName::isPlatform($name)) {
                    throw new GeneratorException(sprintf(
                        'Requirement name "%s" in %s %s is invalid or not lowercase.',
                        $name,
                        $context,
                        $field,
                    ));
                }

                if (!is_string($constraint)) {
                    throw new GeneratorException(sprintf(
                        'Requirement constraint for "%s" in %s %s must be a string.',
                        $name,
                        $context,
                        $field,
                    ));
                }

                if (isset($seen[$name])) {
                    throw new GeneratorException(sprintf(
                        'Requirement "%s" in %s must not appear in both require and require-dev.',
                        $name,
                        $context,
                    ));
                }

                $seen[$name] = true;

                if (PackageName::isPlatform($name)) {
                    $requirements[] = new Requirement($name, $constraint, $development);
                }
            }
        }

        usort(
            $requirements,
            static fn(Requirement $left, Requirement $right): int => strcmp($left->name, $right->name),
        );

        return $requirements;
    }
}
