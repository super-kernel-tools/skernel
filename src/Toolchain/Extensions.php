<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Src\Project\JsonFile;
use Src\Project\Project;
use Symfony\Component\Console\Exception\RuntimeException;

use function array_flip;
use function array_intersect_key;
use function array_map;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function ksort;
use function sort;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

/**
 * Lists the PHP extensions that a project's installation requires, by their static-php-cli names, and the versions of
 * them that it accepts.
 *
 * The root manifest's requirements, its development requirements when they are installed, and the requirements of
 * every installed package count, as do the conflicts of the root manifest and of every installed package. Extensions
 * compiled into every PHP build are left out; phar is always included, because packaging and packaged applications
 * need it.
 */
final class Extensions
{
    private const array BUILT_IN = [
        'core',
        'date',
        'hash',
        'json',
        'pcre',
        'random',
        'reflection',
        'spl',
        'standard',
    ];

    private const array RENAMED = [
        'zend-opcache' => 'opcache',
    ];

    /**
     * @throws RuntimeException When the manifest or the installed package list cannot be read
     */
    public static function required(Project $project): ExtensionRequirements
    {
        $manifest = JsonFile::decode($project->directory . '/composer.json');
        $installed = JsonFile::decode($project->vendorDirectory . '/composer/installed.json');
        $manifest = is_array($manifest) ? $manifest : [];
        $installed = is_array($installed) ? $installed : [];
        $requirements = [
            $manifest['require'] ?? [],
        ];
        $conflicts = [
            $manifest['conflict'] ?? [],
        ];

        if (true === ($installed['dev'] ?? false)) {
            $requirements[] = $manifest['require-dev'] ?? [];
        }

        foreach (is_array($installed['packages'] ?? null) ? $installed['packages'] : [] as $package) {
            $requirements[] = is_array($package) ? $package['require'] ?? [] : [];
            $conflicts[] = is_array($package) ? $package['conflict'] ?? [] : [];
        }

        $extension = static function (mixed $name): ?string {
            $name = strtolower((string) $name);

            if (!str_starts_with($name, 'ext-')) {
                return null;
            }

            $extension = substr($name, 4);
            $extension = self::RENAMED[$extension] ?? $extension;

            return in_array($extension, self::BUILT_IN, true) ? null : $extension;
        };
        $names = [
            'phar',
        ];
        $constraints = [];

        foreach ($requirements as $links) {
            foreach (is_array($links) ? $links : [] as $name => $constraint) {
                $name = $extension($name);

                if (null === $name) {
                    continue;
                }

                $names[] = $name;

                if (is_string($constraint) && '*' !== trim($constraint)) {
                    $constraints[$name][] = $constraint;
                }
            }
        }

        $names = array_values(array_unique($names));
        sort($names);
        $rejected = [];

        foreach ($conflicts as $links) {
            foreach (is_array($links) ? $links : [] as $name => $constraint) {
                $name = $extension($name);

                if (null !== $name && is_string($constraint)) {
                    $rejected[$name][] = $constraint;
                }
            }
        }

        $normalize = static function (array $constraints) use ($names): array {
            $constraints = array_map(static function (array $list): array {
                $list = array_values(array_unique($list));
                sort($list);

                return $list;
            }, array_intersect_key($constraints, array_flip($names)));
            ksort($constraints);

            return $constraints;
        };

        return new ExtensionRequirements($names, $normalize($constraints), $normalize($rejected));
    }
}
