<?php

declare(strict_types=1);

namespace Src\Project;

use Symfony\Component\Console\Exception\RuntimeException;

use function array_column;
use function array_diff;
use function array_filter;
use function getcwd;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * Locates and validates the SuperKernel application that an SKERNEL command works on.
 */
final readonly class ProjectLocator
{
    private const array REQUIRED_PACKAGES = [
        'super-kernel/context',
        'super-kernel/di',
    ];

    /**
     * Uses the given working directory, or the current one when none is given.
     *
     * @throws RuntimeException When the directory does not exist, has no valid composer.json or installed dependencies,
     *                          or does not install SuperKernel's context and DI packages
     */
    public function locate(?string $workingDirectory): Project
    {
        $project = $this->find($workingDirectory);
        $installed = $project->vendorDirectory . '/composer/installed.json';

        if (!is_file($project->vendorDirectory . '/autoload.php') || !is_file($installed)) {
            throw new RuntimeException(sprintf(
                'The project in %s has no installed dependencies; run composer install first.',
                $project->directory,
            ));
        }

        $missing = array_diff(self::REQUIRED_PACKAGES, $this->installedPackages(JsonFile::decode($installed)));

        if ([] !== $missing) {
            throw new RuntimeException(sprintf(
                'The project in %s is not a SuperKernel application: %s is not installed.',
                $project->directory,
                implode(' and ', $missing),
            ));
        }

        return $project;
    }

    /**
     * Uses the given working directory, or the current one when none is given, whether or not the project's
     * dependencies are installed.
     *
     * @throws RuntimeException When the directory does not exist or has no valid composer.json
     */
    public function find(?string $workingDirectory): Project
    {
        $requested = $workingDirectory ?? getcwd();
        $directory = false === $requested ? false : realpath($requested);

        if (false === $directory || !is_dir($directory)) {
            throw new RuntimeException(sprintf('The project directory %s does not exist.', $requested));
        }

        $manifest = $directory . '/composer.json';

        if (!is_file($manifest)) {
            throw new RuntimeException(sprintf('No composer.json found in %s.', $directory));
        }

        $manifest = JsonFile::decode($manifest);
        $name = is_array($manifest) ? $manifest['name'] ?? null : null;
        $require = is_array($manifest) && is_array($manifest['require'] ?? null) ? $manifest['require'] : [];

        return new Project(
            $directory,
            $this->vendorDirectory($directory, $manifest),
            is_string($name) ? $name : null,
            is_string($require['php'] ?? null) ? $require['php'] : null,
        );
    }

    private function vendorDirectory(string $directory, mixed $manifest): string
    {
        $config = is_array($manifest) && is_array($manifest['config'] ?? null) ? $manifest['config'] : [];
        $vendorDirectory = is_string($config['vendor-dir'] ?? null) ? $config['vendor-dir'] : 'vendor';
        $path = str_starts_with($vendorDirectory, '/') ? $vendorDirectory : $directory . '/' . $vendorDirectory;

        return rtrim($path, '/');
    }

    /** @return array<array-key, string> */
    private function installedPackages(mixed $installed): array
    {
        $packages = is_array($installed) && is_array($installed['packages'] ?? null) ? $installed['packages'] : [];

        return array_filter(array_column($packages, 'name'), is_string(...));
    }
}
