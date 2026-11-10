<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Exception;

use function sprintf;

/**
 * Reports a project whose installed super-kernel/metadata expects another build protocol than this SKERNEL generates.
 */
final class ProtocolMismatchException extends GeneratorException
{
    /**
     * @param int|null $project   The project's protocol, or null when it does not install super-kernel/metadata
     * @param int      $generator The protocol this SKERNEL generates
     */
    public function __construct(public readonly ?int $project, public readonly int $generator)
    {
        parent::__construct(match (true) {
            null === $project => 'The project does not install super-kernel/metadata, so SKERNEL cannot publish its '
                . 'build artifacts. Require SuperKernel 1.0.0-alpha.2 or later.',
            $project > $generator => sprintf(
                'The project\'s super-kernel/metadata uses build protocol %d, but this SKERNEL generates protocol %d. '
                . 'Upgrade SKERNEL, for example with "skernel self-update".',
                $project,
                $generator,
            ),
            default => sprintf(
                'The project\'s super-kernel/metadata uses build protocol %d, but this SKERNEL generates protocol %d. '
                . 'Upgrade the project\'s SuperKernel packages, or use a SKERNEL release that generates protocol %d.',
                $project,
                $generator,
                $project,
            ),
        });
    }
}
