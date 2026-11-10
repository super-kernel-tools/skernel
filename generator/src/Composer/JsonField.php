<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Composer;

use SKERNEL\Generator\Exception\GeneratorException;
use stdClass;

use function get_debug_type;
use function is_string;
use function property_exists;
use function sprintf;

/**
 * Reads optional typed fields of a decoded Composer document.
 *
 * @internal
 */
final class JsonField
{
    /**
     * @throws GeneratorException When the field is present but not a string
     */
    public static function string(stdClass $document, string $field, string $context): ?string
    {
        if (!property_exists($document, $field)) {
            return null;
        }

        $value = $document->{$field};

        if (!is_string($value)) {
            throw new GeneratorException(sprintf(
                '%s field "%s" must be a string; %s given.',
                $context,
                $field,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * Accepts an empty JSON array for an empty object, as Composer writes some empty maps.
     *
     * @throws GeneratorException When the field is present but neither an object nor an empty array
     */
    public static function object(stdClass $document, string $field, string $context): ?stdClass
    {
        if (!property_exists($document, $field)) {
            return null;
        }

        $value = $document->{$field};

        if ([] === $value) {
            return new stdClass();
        }

        if (!$value instanceof stdClass) {
            throw new GeneratorException(sprintf(
                '%s field "%s" must be an object; %s given.',
                $context,
                $field,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
