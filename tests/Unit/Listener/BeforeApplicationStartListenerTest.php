<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Listener;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Src\Command\HelpCommand;
use Src\Command\ListCommand;
use Src\Listener\BeforeApplicationStartListener;
use SuperKernel\Attribute\Listener;
use SuperKernel\Contract\ApplicationInterface;
use SuperKernel\Event\BeforeApplicationStart;
use Symfony\Component\Console\Application;

final class BeforeApplicationStartListenerTest extends TestCase
{
    public function testDeclaresTheStartupEvent(): void
    {
        $listener = new BeforeApplicationStartListener(new HelpCommand(), new ListCommand());
        $attributes = new ReflectionClass(BeforeApplicationStartListener::class)->getAttributes(Listener::class);

        self::assertCount(1, $attributes);
        self::assertSame([
            BeforeApplicationStart::class,
        ], $listener->listen());
    }

    public function testPresentsTheConsoleAsSKERNELWithItsOwnHelpAndListCommands(): void
    {
        $helpCommand = new HelpCommand();
        $listCommand = new ListCommand();
        $application = new class() extends Application implements ApplicationInterface {};
        $application->setName('super-kernel/skernel');
        $listener = new BeforeApplicationStartListener($helpCommand, $listCommand);

        $listener->process(new BeforeApplicationStart($application));

        $workingDirectory = $application->getDefinition()->getOption('working-dir');

        self::assertSame('SKERNEL', $application->getName());
        self::assertSame('d', $workingDirectory->getShortcut());
        self::assertTrue($workingDirectory->isValueRequired());
        self::assertSame($helpCommand, $application->get('help'));
        self::assertSame($listCommand, $application->get('list'));
        self::assertTrue($application->has('completion'));
    }

    public function testIgnoresApplicationsWithoutSymfonyConsoleSupport(): void
    {
        $application = $this->createMock(ApplicationInterface::class);
        $application->expects(self::never())->method('run');

        new BeforeApplicationStartListener(new HelpCommand(), new ListCommand())
            ->process(new BeforeApplicationStart($application));
    }
}
