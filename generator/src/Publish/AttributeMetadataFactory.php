<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Publish;

use InvalidArgumentException;
use SKERNEL\Generator\Attribute\CollectedAttribute;
use SuperKernel\Annotator\Model\AttributeMetadata;

/**
 * Turns a collected attribute into the annotator's model, which checks that it can be serialized and restored.
 *
 * @internal
 */
final class AttributeMetadataFactory
{
    /**
     * @throws InvalidArgumentException When the attribute cannot be serialized or restored, or its types are rejected
     */
    public static function create(CollectedAttribute $attribute): AttributeMetadata
    {
        return new AttributeMetadata($attribute->instance, $attribute->types);
    }
}
