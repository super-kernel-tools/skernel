<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Generator;

use SKERNEL\Generator\Autoload\ScanRule;
use SKERNEL\Generator\Autoload\ScanRuleSet;
use SKERNEL\Generator\ClassMap\ClassMapScanner;
use SKERNEL\Generator\Constants\ScanRuleKind;
use SKERNEL\Generator\Constants\ScanRuleSource;

final class ClassMapScannerTest extends GeneratorTestCase
{
    public function testMapsEveryNamedDeclarationAndSkipsParserRejectedFiles(): void
    {
        $source = $this->write('src/Declarations.source', <<<'PHP'
            <?php

            namespace Acme\Multiple;

            final class Service {}
            interface Contract {}
            trait Behavior {}
            enum State {}
            PHP);
        $this->write('src/Rejected.source', '<?php class Broken { public public int $value; }');
        $classMap = ClassMapScanner::scan($this->directory, new ScanRuleSet([
            new ScanRule('src', ScanRuleSource::Classmap, ScanRuleKind::Directory, null),
        ]));

        self::assertSame([
            'Acme\\Multiple\\Behavior' => $source,
            'Acme\\Multiple\\Contract' => $source,
            'Acme\\Multiple\\Service'  => $source,
            'Acme\\Multiple\\State'    => $source,
        ], $classMap->classes);
        self::assertSame([], $classMap->attributeFiles);
    }

    public function testExplicitClassmapWinsOverPsrDirectoryCandidatesAndAcceptsArbitrarySuffixes(): void
    {
        $feature = $this->write('Feature.source', '<?php namespace Acme\\Polyfill; final class Feature {}');
        $this->write('Resources/Deprecated.source', '<?php final class Deprecated {}');
        $stub = $this->write('Resources/stubs/Deprecated.source', '<?php final class Deprecated {}');

        self::assertSame([
            'Acme\\Polyfill\\Feature' => $feature,
            'Deprecated'              => $stub,
        ], ClassMapScanner::scan($this->directory, new ScanRuleSet([
            new ScanRule('.', ScanRuleSource::Psr4, ScanRuleKind::Directory, 'Acme\\Polyfill\\'),
            new ScanRule('Resources/stubs', ScanRuleSource::Classmap, ScanRuleKind::Directory, null),
        ]))->classes);
    }

    public function testPsr4OutranksPsr0AndPrefixesLimitWhatARuleClaims(): void
    {
        $file = $this->write('src/Acme/Service.php', <<<'PHP'
            <?php

            namespace Acme;

            #[Marker]
            final class Service {}
            PHP);
        $this->write('src/Other.php', '<?php namespace Other; final class Other {}');
        $classMap = ClassMapScanner::scan($this->directory, new ScanRuleSet([
            new ScanRule('src', ScanRuleSource::Psr0, ScanRuleKind::Directory, ''),
            new ScanRule('src', ScanRuleSource::Psr4, ScanRuleKind::Directory, 'Acme\\'),
        ]));

        self::assertSame([
            'Acme\\Service' => $file,
            'Other\\Other'  => $this->directory . '/src/Other.php',
        ], $classMap->classes);
        self::assertSame([
            $file => true,
        ], $classMap->attributeFiles);
    }

    public function testExcludedFilesContributeNoClass(): void
    {
        $this->write('src/Tests/ExampleTest.php', '<?php final class ExampleTest {}');
        $kept = $this->write('src/Example.php', '<?php final class Example {}');

        self::assertSame([
            'Example' => $kept,
        ], ClassMapScanner::scan($this->directory, new ScanRuleSet([
            new ScanRule('src', ScanRuleSource::Classmap, ScanRuleKind::Directory, null),
        ], [
            '**/Tests/',
        ]))->classes);
    }
}
