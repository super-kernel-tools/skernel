<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Attribute;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;

use function strcmp;
use function usort;

/**
 * Collects the native attributes of a class and of the methods and properties it declares itself.
 *
 * @internal
 */
final class ClassAttributeCollector
{
    /**
     * Converts the class's attributes first, then those of its methods and properties in name order. Inherited
     * members belong to the class that declares them, so they are left to that class's own scan.
     *
     * @param ReflectionClass<object> $reflection
     *
     * @throws ReflectionException When an attribute class cannot be reflected
     */
    public static function collect(ReflectionClass $reflection): ClassAttributes
    {
        $class = $reflection->getName();
        $classAttributes = AttributeConverter::convert($reflection->getAttributes());
        $members = [
            'methods'    => [],
            'properties' => [],
        ];

        foreach ([
            'methods'    => $reflection->getMethods(),
            'properties' => $reflection->getProperties(),
        ] as $kind => $declarations) {
            usort(
                $declarations,
                static fn(ReflectionMethod|ReflectionProperty $left, ReflectionMethod|ReflectionProperty $right): int
                    => strcmp($left->getName(), $right->getName()),
            );

            foreach ($declarations as $member) {
                if ($class !== $member->getDeclaringClass()->getName()) {
                    continue;
                }

                $attributes = AttributeConverter::convert($member->getAttributes());

                if ([] !== $attributes) {
                    $members[$kind][$member->getName()] = $attributes;
                }
            }
        }

        return new ClassAttributes(
            $classAttributes,
            $members['methods'],
            $members['properties'],
        );
    }
}
