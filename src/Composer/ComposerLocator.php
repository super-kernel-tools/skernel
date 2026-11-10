<?php

declare(strict_types=1);

namespace Src\Composer;

use Src\Process\Executable;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function array_filter;
use function getenv;
use function is_executable;
use function is_file;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Finds the Composer that runs a command: the requested one, the first working Composer 2 in the system's PATH or the
 * user's usual locations, or else SKERNEL's own.
 */
final readonly class ComposerLocator
{
    /** @var list<string> The user's Composer locations, relative to the home directory */
    private const array USER_LOCATIONS = [
        '.local/bin/composer',
        'bin/composer',
        '.config/composer/vendor/bin/composer',
        '.composer/vendor/bin/composer',
    ];

    /** Seconds that a candidate has to report its version */
    private const int VERSION_TIMEOUT = 30;

    public function __construct(private ManagedComposer $managedComposer)
    {
    }

    /**
     * @param string|null $requested A path or a name looked up in PATH, which is used without further checks
     *
     * @return non-empty-list<string> The command that runs Composer
     *
     * @throws RuntimeException When the requested Composer is not found, or SKERNEL's own cannot be provided
     */
    public function command(?string $requested, OutputInterface $output): array
    {
        if (null !== $requested) {
            return [
                Executable::find($requested, 'Composer'),
            ];
        }

        $finder = new ExecutableFinder();
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        $candidates = [
            $finder->find('composer'),
            $finder->find('composer.phar'),
        ];

        foreach ('' === (string) $home ? [] : self::USER_LOCATIONS as $location) {
            $candidates[] = $home . '/' . $location;
        }

        foreach (array_filter($candidates, is_string(...)) as $candidate) {
            if (!is_file($candidate) || !is_executable($candidate)) {
                continue;
            }

            $process = new Process([
                $candidate,
                '--version',
                '--no-ansi',
            ], null, null, null, self::VERSION_TIMEOUT);

            try {
                $process->run();
            } catch (ExceptionInterface) {
                continue;
            }

            if ($process->isSuccessful() && 1 === preg_match(
                '/^Composer(?: version)? [2-9]\./m',
                $process->getOutput(),
            )) {
                return [
                    $candidate,
                ];
            }
        }

        $output->writeln(sprintf(
            'No working Composer 2 was found; using Composer %s in the SKERNEL home.',
            $this->managedComposer->version(),
        ), OutputInterface::OUTPUT_RAW);

        return $this->managedComposer->command($output);
    }
}
