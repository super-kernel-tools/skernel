<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Publish;

use SKERNEL\Generator\Attribute\CollectedAttribute;
use SKERNEL\Generator\Attribute\PackageAttributes;
use SuperKernel\Annotator\Cache\AnnotationPublisher;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Metadata\Build\MetadataPublisher;
use SuperKernel\Metadata\Manager\PackageManager;
use SuperKernel\Metadata\Path\BasePath;
use SuperKernel\Metadata\Path\VendorDir;
use Throwable;

use function array_map;

/**
 * Writes the package and annotation records and the build stamp through the project's own publishers.
 *
 * @internal
 */
final class ArtifactPublisher
{
    /**
     * Removes the previous build first and writes the stamp last, so that a failure leaves no stamp and the
     * application refuses to start until the next build.
     *
     * @param list<PackageAttributes> $attributes     One per package of the manager
     * @param non-empty-string        $skernelVersion
     *
     * @throws Throwable When a publisher fails
     */
    public static function publish(
        PackageManager $manager,
        array          $attributes,
        string         $projectDirectory,
        string         $vendorDirectory,
        string         $skernelVersion,
    ): void
    {
        $vendorDir = new VendorDir($vendorDirectory);
        $publisher = new MetadataPublisher($vendorDir, new BasePath($projectDirectory));
        $convert = static fn(array $list): array => array_map(
            static fn(CollectedAttribute $attribute): AttributeMetadata => new AttributeMetadata(
                $attribute->instance,
                $attribute->types,
            ),
            $list,
        );
        $members = static fn(array $byClass): array => array_map(
            static fn(array $byMember): array => array_map($convert, $byMember),
            $byClass,
        );
        $annotations = [];

        foreach ($attributes as $package) {
            $diagnostics = [];

            foreach ($package->diagnostics as $diagnostic) {
                $diagnostics[] = [
                    'sequence'       => $diagnostic->sequence,
                    'package'        => $diagnostic->package,
                    'class'          => $diagnostic->class,
                    'exception_type' => $diagnostic->type,
                    'message'        => $diagnostic->message,
                ];
            }

            $annotations[] = new PackageAnnotations(
                $package->package,
                $package->reference,
                array_map($convert, $package->classes),
                $members($package->methods),
                $members($package->properties),
                $diagnostics,
            );
        }

        $publisher->invalidate();
        $publisher->publishPackages($manager);
        new AnnotationPublisher($vendorDir)->publish($manager, $annotations);
        $publisher->commit($skernelVersion);
    }
}
