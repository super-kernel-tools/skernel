<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Generator;

use JsonException;
use SKERNEL\Generator\Autoload\AutoloadRuleParser;
use SKERNEL\Generator\Autoload\ScanRule;
use SKERNEL\Generator\Exception\GeneratorException;
use stdClass;

use function array_map;
use function json_decode;
use function json_encode;
use function mkdir;
use function sort;
use function symlink;

use const JSON_THROW_ON_ERROR;
use const SORT_STRING;

final class AutoloadRuleParserTest extends GeneratorTestCase
{
    /** @throws JsonException When a fixture cannot be encoded */
    public function testBuildsRulesForExistingPathsAndNormalizesExclusions(): void
    {
        foreach ([
            'src',
            'lib',
            'pear',
            'class-dir',
            'addons/one/lib',
            'tests',
        ] as $directory) {
            self::assertTrue(mkdir($this->directory . '/' . $directory, 0o700, true));
        }

        $this->write('class-file.php', '<?php');
        $this->write('functions.php', '<?php');
        $rules = AutoloadRuleParser::parse($this->autoload([
            'psr-4' => [
                'App\\' => [
                    'src/',
                    'src/',
                    'lib/',
                ],
                '' => '',
            ],
            'psr-0' => [
                'Legacy\\'    => 'lib/',
                'Pear_'       => 'pear/',
                'UniqueClass' => '',
                ''            => 'src/',
            ],
            'classmap' => [
                'class-dir/',
                'class-file.php',
                'addons/*/lib/',
                'missing/',
            ],
            'files' => [
                'functions.php',
            ],
            'exclude-from-classmap' => [
                '/Tests/',
                '**/Test/',
                'cache/*',
            ],
        ]), $this->directory);

        self::assertSame([
            'classmap|-|addons/*/lib|glob',
            'classmap|-|class-dir|directory',
            'classmap|-|class-file.php|file',
            'psr-0|Legacy\\|lib|directory',
            'psr-0|Pear_|pear|directory',
            'psr-0|UniqueClass|.|directory',
            'psr-0||src|directory',
            'psr-4|App\\|lib|directory',
            'psr-4|App\\|src|directory',
            'psr-4|App\\|src|directory',
            'psr-4||.|directory',
        ], $this->keys($rules->inclusions));
        self::assertSame([
            '**/Test/',
            '/Tests/',
            'cache/*',
        ], $rules->exclusions);
        self::assertTrue($rules->isExcluded('Tests/Example.php'));
        self::assertFalse($rules->isExcluded('src/Tests/Example.php'));
        self::assertFalse($rules->isExcluded('TestsExtra/Example.php'));
        self::assertFalse($rules->isExcluded('Test/Example.php'));
        self::assertTrue($rules->isExcluded('src/Test/Example.php'));
        self::assertTrue($rules->isExcluded('cache/unit/deep/File.php'));
        self::assertFalse($rules->isExcluded('src/cache/unit/File.php'));
        self::assertTrue($rules->isExcluded('src\\Test\\Example.php'));
        self::assertFalse($rules->isExcluded('../Tests/Example.php'));
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testRejectsUnsafeAndMalformedDeclarations(): void
    {
        self::assertTrue(mkdir($this->directory . '/src'));

        foreach ([
            'PSR-4 prefix' => [
                'psr-4' => [
                    'MissingSlash' => 'src/',
                ],
            ],
            'mapping list' => [
                'psr-4' => [
                    'Valid\\' => [],
                ],
            ],
            'absolute path' => [
                'psr-4' => [
                    'Valid\\' => '/tmp/src',
                ],
            ],
            'Windows absolute path' => [
                'psr-4' => [
                    'Valid\\' => 'C:\\tmp\\src',
                ],
            ],
            'parent path' => [
                'classmap' => [
                    '../outside',
                ],
            ],
            'null byte' => [
                'files' => [
                    "safe\0unsafe.php",
                ],
            ],
            'unsupported wildcard' => [
                'classmap' => [
                    'src/?/lib',
                ],
            ],
            'unsafe exclusion' => [
                'exclude-from-classmap' => [
                    '../outside',
                ],
            ],
            'Windows absolute exclusion' => [
                'exclude-from-classmap' => [
                    '/C:/outside',
                ],
            ],
            'classmap object' => [
                'classmap' => [
                    'key' => 'src/',
                ],
            ],
        ] as $label => $autoload) {
            try {
                AutoloadRuleParser::parse($this->autoload($autoload), $this->directory);
                self::fail('Accepted ' . $label . '.');
            } catch (GeneratorException $exception) {
                if ('PSR-4 prefix' === $label) {
                    self::assertStringContainsString('must end in a backslash', $exception->getMessage());
                }
            }
        }
    }

    /** @throws JsonException When a fixture cannot be encoded */
    public function testRejectsSymlinkEscapesForDirectPathsAndClassmapGlobs(): void
    {
        foreach ([
            'escape',
            'glob/*',
        ] as $index => $declaredPath) {
            $root = $this->directory . '/project-' . $index;
            $outside = $this->directory . '/outside-' . $index;
            self::assertTrue(mkdir($root . '/glob', 0o700, true) && mkdir($outside));
            self::assertTrue(
                symlink($outside, 'glob/*' === $declaredPath ? $root . '/glob/outside' : $root . '/escape'),
            );

            try {
                AutoloadRuleParser::parse($this->autoload([
                    'classmap' => [
                        $declaredPath,
                    ],
                ]), $root);
                self::fail('Accepted the escaping path ' . $declaredPath . '.');
            } catch (GeneratorException $exception) {
                self::assertStringContainsString('outside the package root', $exception->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $autoload
     *
     * @throws JsonException When the fixture cannot be encoded
     */
    private function autoload(array $autoload): stdClass
    {
        return json_decode(json_encode($autoload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<ScanRule> $rules
     *
     * @return list<string>
     */
    private function keys(array $rules): array
    {
        $keys = array_map(
            static fn(ScanRule $rule): string => $rule->source->value . '|' . ($rule->namespacePrefix ?? '-') . '|'
                . $rule->relativePath . '|' . $rule->kind->value,
            $rules,
        );
        sort($keys, SORT_STRING);

        return $keys;
    }
}
