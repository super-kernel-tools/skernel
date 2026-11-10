<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Command;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Command\ListCommand;
use SuperKernel\Contract\ApplicationInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ListCommandTest extends TestCase
{
    private const string LOGO = <<<'LOGO'
           _____ __ __ __________  _   __________
          / ___// //_// ____/ __ \/ | / / ____/ /
          \__ \/ ,<  / __/ / /_/ /  |/ / __/ / /
         ___/ / /| |/ /___/ _, _/ /|  / /___/ /___
        /____/_/ |_/_____/_/ |_/_/ |_/_____/_____/
        LOGO;

    /** @return iterable<string, array{array<string, string|bool>}> */
    public static function listingsWithoutTheLogo(): iterable
    {
        yield 'raw listing' => [
            [
                '--raw' => true,
            ],
        ];
        yield 'JSON listing' => [
            [
                '--format' => 'json',
            ],
        ];
        yield 'namespace listing' => [
            [
                'namespace' => 'demo',
            ],
        ];
    }

    public function testDescribesItselfAsTheSKERNELCommandList(): void
    {
        $command = new ListCommand();

        self::assertSame('list', $command->getName());
        self::assertSame('List SKERNEL commands', $command->getDescription());
    }

    /** @throws Exception If Console command initialization or execution fails */
    public function testPlainTextOverviewOpensWithTheLogoAndTheApplicationName(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->run([
            'command' => 'list',
        ]));
        self::assertStringStartsWith(self::LOGO . "\nSKERNEL\n", $tester->getDisplay(true));
    }

    /**
     * @param array<string, string|bool> $options
     *
     * @throws Exception If Console command initialization or execution fails
     */
    #[DataProvider('listingsWithoutTheLogo')]
    public function testOtherListingsOmitTheLogo(array $options): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->run([
            'command' => 'list',
            ...$options,
        ]));
        self::assertStringNotContainsString(self::LOGO, $tester->getDisplay(true));
    }

    private function tester(): ApplicationTester
    {
        $application = new class() extends Application implements ApplicationInterface {};
        $application->setName('SKERNEL');
        $application->setAutoExit(false);
        $application->addCommand(new ListCommand());
        $application->addCommand(new Command('demo:run'));

        return new ApplicationTester($application);
    }
}
