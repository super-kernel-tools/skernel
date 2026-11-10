<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Attribute;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;

use function array_unique;
use function array_values;

/**
 * Instantiates reflected attribute declarations and records each one's assignable types.
 *
 * @internal
 */
final class AttributeConverter
{
    /**
     * @param list<ReflectionAttribute<object>> $attributes
     *
     * @return list<CollectedAttribute> In declaration order
     *
     * @throws ReflectionException When an attribute class cannot be reflected
     */
    public static function convert(array $attributes): array
    {
        $collected = [];

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            $reflection = new ReflectionClass($attribute->getName());
            $types = [
                $reflection->getName(),
            ];
            $parent = $reflection->getParentClass();

            while (false !== $parent) {
                $types[] = $parent->getName();
                $parent = $parent->getParentClass();
            }

            foreach ($reflection->getInterfaceNames() as $interface) {
                $types[] = $interface;
            }

            $collected[] = new CollectedAttribute($instance, array_values(array_unique($types)));
        }

        return $collected;
    }
}
