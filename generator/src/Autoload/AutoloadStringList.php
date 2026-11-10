<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Autoload;

use SKERNEL\Generator\Exception\GeneratorException;
use stdClass;

use function array_is_list;
use function is_array;
use function is_string;
use function property_exists;
use function sprintf;

/**
 * Reads an autoload field that Composer declares as a list of strings, such as `classmap`.
 *
 * @internal
 */
final class AutoloadStringList
{
    /**
     * @return list<string> The declared strings, or an empty list when the field is absent
     *
     * @throws GeneratorException When the field is present but not a list of strings
     */
    public static function read(stdClass $autoload, string $field): array
    {
        if (!property_exists($autoload, $field)) {
            return [];
        }

        $values = $autoload->{$field};

        if (!is_array($values) || !array_is_list($values)) {
            throw new GeneratorException(sprintf('autoload.%s must be a string list.', $field));
        }

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new GeneratorException(sprintf('autoload.%s must contain only strings.', $field));
            }
        }

        return $values;
    }
}
