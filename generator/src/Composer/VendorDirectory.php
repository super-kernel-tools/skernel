<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Composer;

use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Path\FilesystemPath;

use function is_string;
use function property_exists;

/**
 * Resolves a project's vendor directory from the root `config.vendor-dir`, `vendor` by default.
 *
 * @internal
 */
final class VendorDirectory
{
    /**
     * @param string $projectDirectory Canonical project directory
     *
     * @throws GeneratorException When composer.json is unreadable or `config.vendor-dir` is not a resolvable path
     */
    public static function resolve(string $projectDirectory): string
    {
        $config = JsonField::object(JsonFile::decode($projectDirectory . '/composer.json'), 'config', 'composer.json');
        $configured = null !== $config && property_exists($config, 'vendor-dir') ? $config->{'vendor-dir'} : 'vendor';
        $directory = is_string($configured) ? FilesystemPath::absolute($projectDirectory, $configured) : null;

        return $directory ?? throw new GeneratorException(
            'composer.json config.vendor-dir must be a non-empty, resolvable path.',
        );
    }
}
