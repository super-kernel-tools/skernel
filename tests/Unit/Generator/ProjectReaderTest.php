<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Generator;

use JsonException;
use SKERNEL\Generator\Composer\ProjectReader;
use SKERNEL\Generator\Exception\GeneratorException;
use SKERNEL\Generator\Model\PackageRecord;
use SKERNEL\Generator\Model\Requirement;

use function array_map;
use function mkdir;
use function var_export;

final class ProjectReaderTest extends GeneratorTestCase
{
    /** @throws JsonException When a fixture cannot be encoded */
    public function testProductionInstallationSkipsDevelopmentPackagesAndRootDevelopmentClasses(): void
    {
        $this->fixture(false, false);
        $records = $this->read();

        self::assertSame([
            'fixture/project',
            'fixture/production',
        ], $this->names($records));
        self::assertSame([
            'Application\\Example' => $this->directory . '/src/Example.php',
        ], $records[0]->classMap->classes);
        self::assertSame([
            'Production\\Example' => $this->directory . '/vendor/fixture/production/src/Example.php',
        ], $records[1]->classMap->classes);
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testDevelopmentInstallationIncludesDevelopmentPackagesAndRootDevelopmentClasses(): void
    {
        $this->fixture(true, true);
        $records = $this->read();

        self::assertSame([
            'fixture/project',
            'fixture/development',
            'fixture/production',
        ], $this->names($records));
        self::assertSame([
            'ApplicationTests\\Example' => $this->directory . '/tests/Example.php',
            'Application\\Example'      => $this->directory . '/src/Example.php',
        ], $records[0]->classMap->classes);
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testExplicitlyInstalledDevelopmentPackageIsKeptInProductionMode(): void
    {
        $this->fixture(false, true, true);

        self::assertSame([
            'fixture/project',
            'fixture/development',
            'fixture/production',
        ], $this->names($this->read()));
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testMissingPackageDirectoriesAndInvalidInstallationStateAreFatal(): void
    {
        foreach ([
            'missing production directory' => fn() => $this->fixture(false, false, false, false),
            'recorded development package' => fn() => $this->fixture(false, false, true),
            'development mode'             => fn() => $this->fixture(true, false),
        ] as $label => $fixture) {
            $this->tearDown();
            $this->setUp();
            $fixture();

            try {
                $this->read();
                self::fail('Read a project with a ' . $label . '.');
            } catch (GeneratorException $exception) {
                self::assertStringContainsString('is not installed', $exception->getMessage());
            }
        }

        $this->tearDown();
        $this->setUp();
        $this->fixture(false, false);
        $this->write('vendor/composer/installed.php', "<?php return ['root' => []];");
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Invalid Composer installation state');
        $this->read();
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testReadsDeclaredFieldsRequirementsReferencesAndMetapackages(): void
    {
        $this->writeJson('composer.json', [
            'name'    => 'Acme/Project',
            'type'    => 'project',
            'require' => [
                'php'          => '>=8.4',
                'ext-json'     => '*',
                'acme/library' => '^1.0',
            ],
            'require-dev' => [
                'ext-xdebug' => '*',
            ],
        ]);
        $this->writeJson('composer.lock', [
            'packages' => [
                [
                    'name'    => 'acme/library',
                    'version' => '1.2.3',
                    'source'  => [
                        'reference' => 'source-reference',
                    ],
                    'dist' => [
                        'reference' => 'dist-reference',
                    ],
                    'require' => [
                        'ext-swoole' => '^6.0',
                    ],
                    'require-dev' => [
                        'ext-pcov' => '*',
                    ],
                ],
                [
                    'name'    => 'acme/meta',
                    'version' => '1.0.0',
                    'type'    => 'metapackage',
                    'source'  => [
                        'reference' => 'meta-reference',
                    ],
                ],
            ],
            'packages-dev' => [],
        ]);
        $this->installed(false, [
            'acme/library',
            'acme/meta',
        ]);
        self::assertTrue(mkdir($this->directory . '/vendor/acme/library', 0o700, true));
        [$root, $library, $meta] = $this->read();

        self::assertSame('acme/project', $root->declaration->name);
        self::assertNull($root->declaration->version);
        self::assertNull($root->declaration->reference);
        self::assertSame('project', $root->declaration->composerType);
        self::assertSame($this->directory, $root->locatedPath);
        self::assertSame([
            'ext-json|*|0',
            'ext-xdebug|*|1',
            'php|>=8.4|0',
        ], $this->requirements($root));
        self::assertSame('1.2.3', $library->declaration->version);
        self::assertSame('dist-reference', $library->declaration->reference);
        self::assertSame('library', $library->declaration->composerType);
        self::assertSame($this->directory . '/vendor/acme/library', $library->locatedPath);
        self::assertSame([
            'ext-swoole|^6.0|0',
        ], $this->requirements($library));
        self::assertSame('meta-reference', $meta->declaration->reference);
        self::assertNull($meta->locatedPath);
        self::assertSame([], $meta->classMap->classes);
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testRejectsInvalidDeclarations(): void
    {
        foreach ([
            'root without a name' => [
                [
                    'description' => 'A project without a name.',
                ],
                [],
                'must declare a string name',
            ],
            'uppercase requirement' => [
                [
                    'name'    => 'acme/project',
                    'require' => [
                        'Acme/Library' => '^1.0',
                    ],
                ],
                [],
                'invalid or not lowercase',
            ],
            'requirement in both sections' => [
                [
                    'name'    => 'acme/project',
                    'require' => [
                        'php' => '>=8.4',
                    ],
                    'require-dev' => [
                        'php' => '>=8.4',
                    ],
                ],
                [],
                'both require and require-dev',
            ],
            'target-dir' => [
                [
                    'name'       => 'acme/project',
                    'target-dir' => 'Legacy',
                ],
                [],
                '"target-dir"',
            ],
            'lock entry without a version' => [
                [
                    'name' => 'acme/project',
                ],
                [
                    [
                        'name' => 'acme/library',
                    ],
                ],
                'must declare a string version',
            ],
            'duplicate lock entry' => [
                [
                    'name' => 'acme/project',
                ],
                [
                    [
                        'name'    => 'acme/project',
                        'version' => '1.0.0',
                    ],
                ],
                'more than once',
            ],
            'invalid locked autoload' => [
                [
                    'name' => 'acme/project',
                ],
                [
                    [
                        'name'     => 'acme/library',
                        'version'  => '1.0.0',
                        'autoload' => [
                            'psr-4' => [
                                'Acme\\' => '../outside',
                            ],
                        ],
                    ],
                ],
                'Invalid autoload metadata for locked package "acme/library"',
            ],
        ] as $label => [$manifest, $packages, $message]) {
            $this->tearDown();
            $this->setUp();
            $this->writeJson('composer.json', $manifest);
            $this->writeJson('composer.lock', [
                'packages'     => $packages,
                'packages-dev' => [],
            ]);
            $this->installed(false, []);
            self::assertTrue(mkdir($this->directory . '/vendor/acme/library', 0o700, true));

            try {
                $this->read();
                self::fail('Accepted a project with a ' . $label . '.');
            } catch (GeneratorException $exception) {
                self::assertStringContainsString($message, $exception->getMessage(), $label);
            }
        }
    }

    /**
     * @return non-empty-list<PackageRecord>
     *
     * @throws GeneratorException When the project cannot be read
     */
    private function read(): array
    {
        return ProjectReader::read($this->directory, $this->directory . '/vendor');
    }

    /**
     * @param list<PackageRecord> $records
     *
     * @return list<string>
     */
    private function names(array $records): array
    {
        return array_map(static fn(PackageRecord $record): string => $record->declaration->name, $records);
    }

    /**
     * @return list<string>
     */
    private function requirements(PackageRecord $record): array
    {
        return array_map(
            static fn(Requirement $requirement): string => $requirement->name . '|' . $requirement->constraint . '|'
                . (int) $requirement->development,
            $record->declaration->requirements,
        );
    }

    /**
     * Writes a project with one production and one development dependency.
     *
     * @throws JsonException When a fixture cannot be encoded
     */
    private function fixture(
        bool $development,
        bool $developmentInstalled,
        bool $recordDevelopment = false,
        bool $productionInstalled = true,
    ): void
    {
        $this->writeJson('composer.json', [
            'name'     => 'fixture/project',
            'autoload' => [
                'psr-4' => [
                    'Application\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'ApplicationTests\\' => 'tests/',
                ],
            ],
        ]);
        $this->writeJson('composer.lock', [
            'packages' => [
                [
                    'name'     => 'fixture/production',
                    'version'  => '1.0.0',
                    'autoload' => [
                        'psr-4' => [
                            'Production\\' => 'src/',
                        ],
                    ],
                ],
            ],
            'packages-dev' => [
                [
                    'name'     => 'fixture/development',
                    'version'  => '1.0.0',
                    'autoload' => [
                        'psr-4' => [
                            'Development\\' => 'src/',
                        ],
                    ],
                ],
            ],
        ]);
        $this->write('src/Example.php', '<?php namespace Application; final class Example {}');
        $this->write('tests/Example.php', '<?php namespace ApplicationTests; final class Example {}');

        if ($productionInstalled) {
            $this->write(
                'vendor/fixture/production/src/Example.php',
                '<?php namespace Production; final class Example {}',
            );
        }

        if ($developmentInstalled) {
            $this->write(
                'vendor/fixture/development/src/Example.php',
                '<?php namespace Development; final class Example {}',
            );
        }

        $installed = [
            'fixture/production',
        ];

        if ($development || $recordDevelopment) {
            $installed[] = 'fixture/development';
        }

        $this->installed($development, $installed);
    }

    /**
     * @param list<string> $packages
     */
    private function installed(bool $development, array $packages): void
    {
        $versions = [];

        foreach ($packages as $package) {
            $versions[$package] = [
                'dev_requirement' => false,
            ];
        }

        $this->write('vendor/composer/installed.php', '<?php return ' . var_export([
            'root' => [
                'dev' => $development,
            ],
            'versions' => $versions,
        ], true) . ';');
    }
}
