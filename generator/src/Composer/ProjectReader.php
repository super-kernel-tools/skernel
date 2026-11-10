<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Composer;

use SKERNEL\Generator\Autoload\AutoloadRuleParser;
use SKERNEL\Generator\ClassMap\ClassMap;
use SKERNEL\Generator\ClassMap\ClassMapScanner;
use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Model\PackageDeclaration;
use SKERNEL\Generator\Model\PackageRecord;
use SKERNEL\Generator\Path\FilesystemPath;
use stdClass;

use function is_array;
use function ksort;
use function sprintf;

use const SORT_STRING;

/**
 * Reads a project's composer.json, composer.lock, and installation state into located packages with class maps.
 *
 * @internal
 */
final class ProjectReader
{
    /**
     * Lists the root first, then the installed lock packages sorted by name. The root's autoload-dev classes belong to
     * its class map only when Composer installed development dependencies, because only then does Composer's own
     * autoloader load them.
     *
     * @param string $projectDirectory Canonical project directory
     * @param string $vendorDirectory  Canonical vendor directory
     *
     * @return non-empty-list<PackageRecord>
     *
     * @throws GeneratorException When a Composer file is missing or invalid, or an installed package has no directory
     */
    public static function read(string $projectDirectory, string $vendorDirectory): array
    {
        $installed = InstalledState::read($vendorDirectory);
        $root = DeclarationReader::read(
            JsonFile::decode($projectDirectory . '/composer.json'),
            true,
            false,
            'composer.json',
        );
        $lockPath = $projectDirectory . '/composer.lock';
        $lock = JsonFile::decode($lockPath);
        $declarations = [];

        foreach ([
            'packages'     => false,
            'packages-dev' => true,
        ] as $field => $development) {
            $entries = $lock->{$field} ?? null;

            if (!is_array($entries)) {
                throw new GeneratorException(sprintf('%s must declare the list "%s".', $lockPath, $field));
            }

            foreach ($entries as $index => $entry) {
                $context = sprintf('composer.lock %s[%d]', $field, $index);

                if (!$entry instanceof stdClass) {
                    throw new GeneratorException(sprintf('%s must be an object.', $context));
                }

                $declaration = DeclarationReader::read($entry, false, $development, $context);

                if ($declaration->name === $root->name || isset($declarations[$declaration->name])) {
                    throw new GeneratorException(sprintf(
                        'composer.lock declares the package "%s" more than once.',
                        $declaration->name,
                    ));
                }

                $declarations[$declaration->name] = $declaration;
            }
        }

        ksort($declarations, SORT_STRING);
        $records = [
            new PackageRecord(
                $root,
                $projectDirectory,
                ClassMapScanner::scan(
                    $projectDirectory,
                    $installed->development
                        ? AutoloadRuleParser::parse($root->autoload, $projectDirectory)->merge(
                            AutoloadRuleParser::parse($root->autoloadDev, $projectDirectory),
                        )
                        : AutoloadRuleParser::parse($root->autoload, $projectDirectory),
                ),
            ),
        ];

        foreach ($declarations as $declaration) {
            if ($installed->contains($declaration->name, $declaration->development)) {
                $records[] = self::locate($declaration, $vendorDirectory);
            }
        }

        return $records;
    }

    /**
     * @throws GeneratorException When the package has no directory below the vendor directory or invalid autoload rules
     */
    private static function locate(PackageDeclaration $declaration, string $vendorDirectory): PackageRecord
    {
        if ('metapackage' === $declaration->composerType) {
            return new PackageRecord($declaration, null, new ClassMap());
        }

        $candidate = $vendorDirectory . '/' . $declaration->name;
        $directory = FilesystemPath::canonicalDirectory($candidate) ?? throw new GeneratorException(sprintf(
            'Package "%s" is not installed at %s. Install the project\'s dependencies first.',
            $declaration->name,
            $candidate,
        ));

        try {
            $scanRules = AutoloadRuleParser::parse($declaration->autoload, $directory);
        } catch (GeneratorException $exception) {
            throw new GeneratorException(sprintf(
                'Invalid autoload metadata for locked package "%s": %s',
                $declaration->name,
                $exception->getMessage(),
            ), previous: $exception);
        }

        return new PackageRecord($declaration, $directory, ClassMapScanner::scan($directory, $scanRules));
    }
}
