<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Publish;

use SKERNEL\Generator\Exception\ProtocolMismatchException;
use SuperKernel\Metadata\Constants\Protocol as MetadataProtocol;

use function class_exists;

/**
 * Compares the build protocol of the installed super-kernel/metadata with the one this generator writes.
 *
 * @internal
 */
final class Protocol
{
    private const int SUPPORTED = 1;

    public static function supported(): int
    {
        return self::SUPPORTED;
    }

    /**
     * Reads the protocol through the project's autoloader, before anything is scanned or written.
     *
     * @throws ProtocolMismatchException When the project does not install super-kernel/metadata or uses another
     *                                   protocol
     */
    public static function assertCompatible(): void
    {
        $project = class_exists(MetadataProtocol::class) ? MetadataProtocol::VERSION : null;

        if (self::SUPPORTED !== $project) {
            throw new ProtocolMismatchException($project, self::SUPPORTED);
        }
    }
}
