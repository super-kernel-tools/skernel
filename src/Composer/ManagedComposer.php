<?php

declare(strict_types=1);

namespace Src\Composer;

use Src\Filesystem\Directory;
use Src\Toolchain\Download;
use Src\Toolchain\ExtensionRequirements;
use Src\Toolchain\Home;
use Src\Toolchain\PhpReleases;
use Src\Toolchain\RuntimeProvider;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

use function dirname;
use function is_file;
use function sprintf;

/**
 * Provides SKERNEL's own Composer: a pinned, verified composer.phar in the SKERNEL home, run with a managed PHP.
 *
 * The PHP is built on first use, because a standalone SKERNEL cannot run a PHAR other than itself.
 */
final readonly class ManagedComposer
{
    private const string VERSION = '2.11.1';

    private const string RELEASES = 'https://getcomposer.org/download/%s/composer.phar';

    private const string SHA256 = '6f1b891e1d4927d62740276c540fb490e47689e19e102c49e8da849794882f8c';

    /** The PHP branch that runs Composer, which is the one SuperKernel requires */
    private const string PHP = '8.4';

    /** @var list<string> The extensions that Composer needs to download and unpack packages */
    private const array EXTENSIONS = [
        'curl',
        'filter',
        'iconv',
        'openssl',
        'phar',
        'tokenizer',
        'zlib',
    ];

    public function __construct(
        private Home            $home,
        private PhpReleases     $releases,
        private RuntimeProvider $runtimes,
    )
    {
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @return non-empty-list<string> The command that runs Composer
     *
     * @throws RuntimeException When Composer cannot be downloaded and verified, or its PHP cannot be provided
     */
    public function command(OutputInterface $output): array
    {
        $composer = $this->home->directory() . '/tools/composer/' . self::VERSION . '/composer.phar';

        if (!is_file($composer)) {
            Directory::create(dirname($composer));
            Download::file(sprintf(self::RELEASES, self::VERSION), $composer, self::SHA256);
        }

        return [
            $this->runtimes->provide(
                $this->releases->resolve(self::PHP, null),
                new ExtensionRequirements(self::EXTENSIONS),
                $output,
            )->php,
            $composer,
        ];
    }
}
