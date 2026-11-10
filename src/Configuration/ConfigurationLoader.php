<?php

declare(strict_types=1);

namespace Src\Configuration;

use Src\Project\AbsolutePath;
use Src\Project\JsonFile;
use stdClass;
use Symfony\Component\Console\Exception\RuntimeException;

use function is_file;

/**
 * Reads a project's `skernel.json`, or `skernel.json.dist` when it is absent, into validated settings.
 *
 * The two files are never merged. Relative paths in the file resolve against the project directory.
 */
final readonly class ConfigurationLoader
{
    private const array FILES = [
        'skernel.json',
        'skernel.json.dist',
    ];

    /**
     * @throws RuntimeException When the file is not valid JSON or holds an unknown setting or a value of the wrong type
     */
    public function load(string $projectDirectory): ProjectConfiguration
    {
        foreach (self::FILES as $file) {
            if (!is_file($projectDirectory . '/' . $file)) {
                continue;
            }

            $settings = Setting::object(JsonFile::decode($projectDirectory . '/' . $file, false), $file, '', [
                '$schema',
                'php',
                'package',
            ]);
            Setting::string($settings['$schema'] ?? null, $file, '$schema');
            $package = Setting::object($settings['package'] ?? new stdClass(), $file, 'package', [
                'runtime',
                'output',
                'composer',
                'dev',
                'phar',
            ]);
            $runtime = Setting::string($package['runtime'] ?? null, $file, 'package.runtime');
            $output = Setting::string($package['output'] ?? null, $file, 'package.output');

            return new ProjectConfiguration(
                Setting::version($settings['php'] ?? null, $file, 'php'),
                new PackageConfiguration(
                    null === $runtime ? null : AbsolutePath::resolve($runtime, $projectDirectory),
                    null === $output ? null : AbsolutePath::resolve($output, $projectDirectory),
                    Setting::string($package['composer'] ?? null, $file, 'package.composer'),
                    Setting::boolean($package['dev'] ?? null, $file, 'package.dev'),
                    Setting::boolean($package['phar'] ?? null, $file, 'package.phar'),
                ),
            );
        }

        return new ProjectConfiguration();
    }
}
