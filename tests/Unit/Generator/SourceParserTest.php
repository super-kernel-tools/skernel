<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use SKERNEL\Generator\ClassMap\SourceParser;

final class SourceParserTest extends TestCase
{
    public function testParsesEveryNamedClassLikeDeclarationAndIgnoresAnonymousClasses(): void
    {
        $parsed = SourceParser::parse(<<<'PHP'
            <?php

            namespace Acme\Feature;

            final class /* declaration comment */ Service {}
            interface Contract {}
            trait Behavior {}
            enum State {}

            $anonymous = new class {};
            $classConstant = Service::class;
            PHP);

        self::assertNotNull($parsed);
        self::assertSame([
            'Acme\\Feature\\Behavior',
            'Acme\\Feature\\Contract',
            'Acme\\Feature\\Service',
            'Acme\\Feature\\State',
        ], $parsed->classNames);
        self::assertFalse($parsed->hasAttributes);
    }

    public function testSupportsBracketedAndGlobalNamespaces(): void
    {
        self::assertSame([
            'First\\Space\\One',
            'GlobalClass',
        ], SourceParser::parse(<<<'PHP'
            <?php

            namespace First\Space {
                class One {}
            }

            namespace {
                class GlobalClass {}
            }
            PHP)?->classNames);
    }

    public function testTracksMultipleUnbracketedNamespacesAndDeduplicatesNames(): void
    {
        self::assertSame([
            'First\\Space\\Duplicate',
            'First\\Space\\Zed',
            'Second\\Space\\Alpha',
        ], SourceParser::parse(<<<'PHP'
            <?php

            namespace First\Space;

            namespace\helper();
            class Zed {}

            if (true) {
                class Duplicate {}
            } else {
                class Duplicate {}
            }

            namespace Second\Space;

            interface Alpha {}
            PHP)?->classNames);
    }

    public function testReportsWhetherTheSourceUsesNativeAttributes(): void
    {
        self::assertTrue(SourceParser::parse('<?php #[Marker] final class Marked {}')?->hasAttributes);
        self::assertFalse(SourceParser::parse('<?php /* #[Marker] */ final class Unmarked {}')?->hasAttributes);
    }

    public function testReturnsNullForEmptyOrDeclarationFreeSource(): void
    {
        self::assertNull(SourceParser::parse(''));
        self::assertNull(SourceParser::parse('<?php function helper(): void {}'));
    }

    public function testReturnsNullForParserValidationErrors(): void
    {
        self::assertNull(SourceParser::parse('<?php class'));
        self::assertNull(SourceParser::parse('<?php class Broken { public public int $value; }'));
        self::assertNull(SourceParser::parse('<?php abstract final class Broken {}'));
    }
}
