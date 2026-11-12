<?php

declare(strict_types=1);

namespace Src\Configuration;

use Src\Project\AbsolutePath;
use Src\Project\JsonFile;
use stdClass;
use Symfony\Component\Console\Exception\RuntimeException;

use function is_file;
use function str_contains;

/**
 * Reads a project's `skernel.json`, or `skernel.json.dist` when it is absent, into validated settings; a setting that
 * the file leaves out keeps its default.
 *
 * The two files are never merged. Relative paths in the file resolve against the project directory; a Composer
 * without a `/` is a name looked up in PATH.
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
                'composer',
                'run',
                'package',
            ]);
            Setting::string($settings['$schema'] ?? null, $file, '$schema');
            $composer = Setting::string($settings['composer'] ?? null, $file, 'composer');
            $run = Setting::object($settings['run'] ?? new stdClass(), $file, 'run', [
                'watch',
                'watch-files',
            ]);
            $watch = Setting::boolean($run['watch'] ?? null, $file, 'run.watch') ?? false;
            $watchFiles = Setting::list($run['watch-files'] ?? null, $file, 'run.watch-files');
            $package = Setting::object($settings['package'] ?? new stdClass(), $file, 'package', [
                'runtime',
                'output',
                'dev',
                'phar',
            ]);
            $runtime = Setting::string($package['runtime'] ?? null, $file, 'package.runtime');
            $output = Setting::string($package['output'] ?? null, $file, 'package.output');

            return new ProjectConfiguration(
                Setting::version($settings['php'] ?? null, $file, 'php'),
                null !== $composer && str_contains($composer, '/')
                    ? AbsolutePath::resolve($composer, $projectDirectory)
                    : $composer,
                null === $watchFiles ? new RunConfiguration($watch) : new RunConfiguration($watch, $watchFiles),
                new PackageConfiguration(
                    null === $runtime ? null : AbsolutePath::resolve($runtime, $projectDirectory),
                    null === $output ? null : AbsolutePath::resolve($output, $projectDirectory),
                    Setting::boolean($package['dev'] ?? null, $file, 'package.dev') ?? false,
                    Setting::boolean($package['phar'] ?? null, $file, 'package.phar') ?? false,
                ),
            );
        }

        return new ProjectConfiguration();
    }
}
