<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Generator;

use Closure;
use SKERNEL\Generator\Attribute\AttributeScanner;
use SKERNEL\Generator\Attribute\CollectedAttribute;
use SKERNEL\Generator\Attribute\ScanProgress;
use SKERNEL\Generator\ClassMap\ClassMap;
use SKERNEL\Generator\ClassMap\SourceParser;
use SKERNEL\Generator\Diagnostic\Diagnostic;
use SKERNEL\Generator\Model\PackageDeclaration;
use SKERNEL\Generator\Model\PackageRecord;
use SKERNEL\Generator\Publish\AttributeMetadataFactory;
use SuperKernel\Annotator\Model\AttributeMetadata;

use function array_keys;
use function array_map;
use function class_exists;
use function file_get_contents;
use function ksort;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_replace;
use function uniqid;

final class AttributeScannerTest extends GeneratorTestCase
{
    private string $namespace;

    /** @var array<class-string, string> */
    private array $files = [];

    private Closure $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->namespace = 'GeneratorFixture' . str_replace('.', '', uniqid('', true));
        $this->loader = function (string $class): void {
            if (isset($this->files[$class])) {
                require $this->files[$class];
            }
        };
        spl_autoload_register($this->loader);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->loader);
        parent::tearDown();
    }

    public function testCollectsClassMethodAndPropertyAttributesOfDeclaredMembersByPackage(): void
    {
        $this->declare('Contract', 'interface Contract {}');
        $this->declare('BaseAttribute', 'abstract class BaseAttribute implements Contract {}');
        $this->declare('Marker', <<<'PHP'
            #[\Attribute(\Attribute::TARGET_ALL | \Attribute::IS_REPEATABLE)]
            final class Marker extends BaseAttribute
            {
                public function __construct(public string $value = '')
                {
                }
            }
            PHP);
        $this->declare('Base', <<<'PHP'
            class Base
            {
                #[Marker('inherited')]
                public function inherited(): void
                {
                }
            }
            PHP);
        $service = $this->declare('Service', <<<'PHP'
            #[Marker('class'), Marker('again')]
            final class Service extends Base
            {
                #[Marker('property')]
                public int $count = 0;

                #[Marker('zeta')]
                public function zeta(): void
                {
                }

                #[Marker('alpha')]
                public function alpha(): void
                {
                }

                public function plain(): void
                {
                }
            }
            PHP);
        $other = $this->declare('Other', '#[Marker] final class Other {}');
        [$first, $second] = AttributeScanner::scan([
            $this->record('acme/first', 'first-reference', [
                'Service',
                'Base',
            ]),
            $this->record('acme/second', null, [
                'Other',
            ]),
        ], [], new ScanProgress(), $this->identity(...));

        self::assertSame('acme/first', $first->package);
        self::assertSame('first-reference', $first->reference);
        self::assertSame([
            $this->className('Base'),
            $service,
        ], array_keys($first->methods));
        self::assertSame([
            'class',
            'again',
        ], $this->values($first->classes[$service]));
        self::assertSame([
            $this->className('Marker'),
            $this->className('BaseAttribute'),
            $this->className('Contract'),
        ], $first->classes[$service][0]->types);
        self::assertSame([
            'alpha',
            'zeta',
        ], array_keys($first->methods[$service]));
        self::assertSame([
            'count',
        ], array_keys($first->properties[$service]));
        self::assertSame([
            'inherited',
        ], array_keys($first->methods[$this->className('Base')]));
        self::assertSame([], $first->diagnostics);
        self::assertSame('acme/second', $second->package);
        self::assertNull($second->reference);
        self::assertSame([
            $other,
        ], array_keys($second->classes));
    }

    public function testSkipsFailingClassesWithDiagnosticsInScanOrderAcrossPackages(): void
    {
        $this->declare('Once', '#[\Attribute] final class Once {}');
        $this->declare('Failing', <<<'PHP'
            #[\Attribute]
            final class Failing
            {
                public function __construct()
                {
                    throw new \RuntimeException("Attribute\nfailed");
                }
            }
            PHP);
        $this->declare('Kept', '#[Once] final class Kept {}');
        $this->declare('MissingParent', '#[Once] final class MissingParent extends Absent {}');
        $this->declare('Repeated', '#[Once, Once] final class Repeated {}');
        $this->declare('Throwing', 'final class Throwing { #[Failing] public function run(): void {} }');
        $this->declare('UnknownAttribute', '#[Unknown] final class UnknownAttribute {}');
        $this->declare('Fatal', '#[Once] final class Fatal {}');
        $progress = new ScanProgress();
        [$first, $second] = AttributeScanner::scan([
            $this->record('acme/first', null, [
                'Kept',
                'MissingParent',
                'Repeated',
            ]),
            $this->record('acme/second', null, [
                'Throwing',
                'UnknownAttribute',
                'Fatal',
            ]),
        ], [
            $this->className('Fatal') => 'Cannot redeclare a method',
        ], $progress, $this->identity(...));

        self::assertSame([
            $this->className('Kept'),
        ], array_keys($first->classes));
        self::assertSame([
            '1|acme/first|MissingParent|Error',
            '2|acme/first|Repeated|Error',
        ], $this->diagnostics($first->diagnostics));
        self::assertSame([
            '3|acme/second|Fatal|fatal error',
            '4|acme/second|Throwing|RuntimeException',
            '5|acme/second|UnknownAttribute|Error',
        ], $this->diagnostics($second->diagnostics));
        self::assertSame("Attribute\nfailed", $second->diagnostics[1]->message);
        self::assertSame('Cannot redeclare a method', $second->diagnostics[0]->message);
        self::assertFalse(class_exists($this->className('Fatal'), false));
        self::assertNull($progress->class);
        self::assertNull($progress->package);
    }

    public function testSkipsAClassWithAnAttributeThatCannotBeSerializedWithoutPartialMetadata(): void
    {
        $this->declare('Plain', '#[\Attribute] final class Plain {}');
        $this->declare('Holder', <<<'PHP'
            #[\Attribute]
            final class Holder
            {
                public \Closure $factory;

                public function __construct()
                {
                    $this->factory = static fn(): int => 1;
                }
            }
            PHP);
        $this->declare('Holding', <<<'PHP'
            #[Plain]
            final class Holding
            {
                #[Holder]
                public function run(): void
                {
                }
            }
            PHP);
        $kept = $this->declare('Kept', '#[Plain] final class Kept {}');
        [$package] = AttributeScanner::scan([
            $this->record('acme/first', null, [
                'Holding',
                'Kept',
            ]),
        ], [], new ScanProgress(), AttributeMetadataFactory::create(...));

        self::assertSame([
            $kept,
        ], array_keys($package->classes));
        self::assertInstanceOf(AttributeMetadata::class, $package->classes[$kept][0]);
        self::assertSame([], $package->methods);
        self::assertSame([
            '1|acme/first|Holding|InvalidArgumentException',
        ], $this->diagnostics($package->diagnostics));
        self::assertSame(
            'Attribute ' . $this->className('Holder') . ' must support trusted serialization and restoration.',
            $package->diagnostics[0]->message,
        );
    }

    public function testDoesNotLoadAClassWhoseSourceContainsNoAttributes(): void
    {
        $this->declare('Unmarked', 'final class Unmarked extends Absent {}');
        [$package] = AttributeScanner::scan([
            $this->record('acme/first', null, [
                'Unmarked',
            ]),
        ], [], new ScanProgress(), $this->identity(...));

        self::assertSame([], $package->classes);
        self::assertSame([], $package->diagnostics);
        self::assertFalse(class_exists($this->className('Unmarked'), false));
    }

    /**
     * Writes a fixture declaration in this test's namespace and makes it autoloadable.
     *
     * @return class-string
     */
    private function declare(string $class, string $declaration): string
    {
        $name = $this->className($class);
        $this->files[$name] = $this->write(
            $class . '.php',
            "<?php\n\nnamespace " . $this->namespace . ";\n\n" . $declaration . "\n",
        );

        return $name;
    }

    /**
     * @return class-string
     */
    private function className(string $class): string
    {
        return $this->namespace . '\\' . $class;
    }

    /**
     * @param list<string> $classes Short names in this test's namespace
     */
    private function record(string $package, ?string $reference, array $classes): PackageRecord
    {
        $map = [];
        $attributeFiles = [];

        foreach ($classes as $class) {
            $file = $this->files[$this->className($class)];
            $map[$this->className($class)] = $file;

            if (true === SourceParser::parse((string) file_get_contents($file))?->hasAttributes) {
                $attributeFiles[$file] = true;
            }
        }

        ksort($map);

        return new PackageRecord(
            new PackageDeclaration($package, null, $reference, 'library', false, false, null, null, []),
            $this->directory,
            new ClassMap($map, $attributeFiles),
        );
    }

    private function identity(CollectedAttribute $attribute): CollectedAttribute
    {
        return $attribute;
    }

    /**
     * @param list<CollectedAttribute> $attributes
     *
     * @return list<string>
     */
    private function values(array $attributes): array
    {
        return array_map(static fn(CollectedAttribute $attribute): string => $attribute->instance->value, $attributes);
    }

    /**
     * @param list<Diagnostic> $diagnostics
     *
     * @return list<string>
     */
    private function diagnostics(array $diagnostics): array
    {
        return array_map(
            fn(Diagnostic $diagnostic): string => $diagnostic->sequence . '|' . $diagnostic->package . '|'
                . str_replace($this->namespace . '\\', '', $diagnostic->class) . '|' . $diagnostic->type,
            $diagnostics,
        );
    }
}
