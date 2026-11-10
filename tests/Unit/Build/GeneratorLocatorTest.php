<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use Src\Build\GeneratorLocator;
use Src\Filesystem\Directory;
use Src\Toolchain\Home;
use Symfony\Component\Process\Process;

use function basename;
use function dirname;
use function glob;
use function mkdir;
use function putenv;
use function realpath;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

use const PHP_BINARY;

final class GeneratorLocatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'skernel-generator-locator-');
        self::assertIsString($directory);
        self::assertTrue(unlink($directory) && mkdir($directory, 0o700));
        $this->directory = (string) realpath($directory);
        putenv('SKERNEL_HOME=' . $this->directory . '/home');
    }

    protected function tearDown(): void
    {
        putenv('SKERNEL_HOME');
        Directory::remove($this->directory);
    }

    public function testRunsTheGeneratorInPlaceFromSource(): void
    {
        self::assertSame(dirname(__DIR__, 3) . '/generator', new GeneratorLocator(new Home())->directory());
    }

    public function testExtractsTheGeneratorOfAPharToTheHomeByItsDigestAndReusesIt(): void
    {
        $phar = $this->directory . '/skernel.phar';
        $process = new Process([
            PHP_BINARY,
            '-d',
            'phar.readonly=0',
            '-r',
            '$phar = new Phar(' . var_export($phar, true) . ');'
            . '$phar->addFromString("generator/generate.php", "<?php // entry");'
            . '$phar->addFromString("generator/src/Generator.php", "<?php // generator");'
            . '$phar->addFromString("src/App.php", "<?php // not the generator");',
        ]);
        self::assertSame(0, $process->run(), $process->getErrorOutput());

        $locator = new GeneratorLocator(new Home());
        $directory = $locator->extract('phar://' . $phar . '/generator');

        self::assertSame($this->directory . '/home/generator', dirname($directory));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', basename($directory));
        self::assertStringEqualsFile($directory . '/generate.php', '<?php // entry');
        self::assertStringEqualsFile($directory . '/src/Generator.php', '<?php // generator');
        self::assertFileDoesNotExist($directory . '/src/App.php');
        self::assertSame([], glob($this->directory . '/home/work/*'));

        self::assertTrue(unlink($directory . '/src/Generator.php'));
        self::assertSame($directory, $locator->extract('phar://' . $phar . '/generator'));
        self::assertFileDoesNotExist($directory . '/src/Generator.php');
    }
}
