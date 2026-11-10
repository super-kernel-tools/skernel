<?php

declare(strict_types=1);

namespace Src\Toolchain;

use PharData;
use Src\Filesystem\Directory;
use Src\Process\ProcessRunner;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function chmod;
use function escapeshellarg;
use function exec;
use function is_executable;
use function is_file;
use function rename;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;
use function uniqid;
use function unlink;

/**
 * Runs the pinned static-php-cli release, downloading and verifying its self-contained binary on first use.
 *
 * Its downloads and packages live in the SKERNEL home; sources and build output stay in the caller's job. On Linux it
 * builds statically linked musl binaries for the architecture's baseline CPU with a pinned Zig release, which is
 * downloaded from a Zig community mirror or ziglang.org, verified, and installed into its package root before the first
 * run, so no system toolchain has to be installed with sudo. static-php-cli also installs its own pkg-config there,
 * as its doctor command would; SKERNEL never runs that command, which may try to install system packages with sudo.
 */
final readonly class StaticPhpCli
{
    private const string VERSION = '2.8.5';

    private const string RELEASES = 'https://github.com/crazywhalecc/static-php-cli/releases/download/';

    /**
     * The Zig release that static-php-cli VERSION builds with. Zig 0.16 cannot build micro: its memchr reads past the
     * end of a string, which crashes the realpath call that micro makes at startup on the path at the top of its stack.
     */
    private const string ZIG = '0.17.1';

    /** @var array<string, array{string, string}> Zig archive and SHA-256 digest by Linux platform */
    private const array ZIG_ASSETS = [
        'linux-aarch64' => [
            'zig-aarch64-linux-0.17.1.tar.xz',
            'a1546d5646eb184437918d6635ca6b762a0e889eac09cef720ee7a1ed3fe7795',
        ],
        'linux-x86_64' => [
            'zig-x86_64-linux-0.17.1.tar.xz',
            '3581863e3cbcfd0fce0efc682ce7e9e93da96668a977791d356a26d2a7e9b17a',
        ],
    ];

    /** @var list<string> The files static-php-cli requires of an installed Zig */
    private const array ZIG_FILES = [
        'zig',
        'zig-cc',
        'zig-c++',
        'zig-ar',
        'zig-ld.lld',
        'zig-ranlib',
        'zig-objcopy',
    ];

    /** @var array<string, array{string, string}> Release asset and SHA-256 digest by platform */
    private const array ASSETS = [
        'linux-aarch64' => [
            'spc-linux-aarch64.tar.gz',
            '675a3840dcdc4ed041fe20eaa54310ce019a9984c1c03951df9ec66df5795213',
        ],
        'linux-x86_64' => [
            'spc-linux-x86_64.tar.gz',
            '523ba4279c54c7a377156c0dd3a36adf92ee64b01e9a7f5e9e2ec084b8e458e5',
        ],
        'macos-aarch64' => [
            'spc-macos-aarch64.tar.gz',
            'acf2f25d56d0cbf8e65aa82e5054fef555f7be7c5c38046c6e0819f266d83225',
        ],
        'macos-x86_64' => [
            'spc-macos-x86_64.tar.gz',
            'e8b798048f62ca4960764196543b60ae703f7174aa418824cf542aeec1d2cd6a',
        ],
        'windows-x86_64' => [
            'spc-windows-x64.exe',
            '425b54ab857e21409c1fd9b818899ffebabd1e2817ef0a0ed5ae8a3d9f5b463b',
        ],
    ];

    public function __construct(private Home $home, private ZigMirrors $zigMirrors)
    {
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /** Identifies the releases that build a runtime, which a change of either must rebuild */
    public function toolchain(): string
    {
        return self::VERSION . ' zig-' . self::ZIG;
    }

    /**
     * Runs a static-php-cli command in a job directory, which also holds its sources and build output.
     *
     * @param non-empty-list<string> $arguments
     *
     * @return int The exit status of static-php-cli
     *
     * @throws RuntimeException When the platform is unsupported, or the binary, Zig, or pkg-config cannot be installed
     *                          or started
     */
    public function run(array $arguments, string $job, OutputInterface $output): int
    {
        $home = $this->home->directory();
        $platform = Platform::current();
        [$asset, $digest] = self::ASSETS[$platform] ?? throw new RuntimeException(sprintf(
            'static-php-cli %s has no release for %s.',
            self::VERSION,
            $platform,
        ));
        $directory = $home . '/tools/spc/' . self::VERSION;
        $executable = $directory . (str_starts_with($platform, 'windows-') ? '/spc.exe' : '/spc');

        if (!is_file($executable)) {
            $staging = $directory . '.' . uniqid('', true);

            try {
                Directory::create($staging);
                Download::file(self::RELEASES . self::VERSION . '/' . $asset, $staging . '/' . $asset, $digest);

                if (str_ends_with($asset, '.tar.gz')) {
                    new PharData($staging . '/' . $asset)->extractTo($staging, null, true);
                    unlink($staging . '/' . $asset);
                } else {
                    rename($staging . '/' . $asset, $staging . '/spc.exe');
                }

                chmod($staging . (str_starts_with($platform, 'windows-') ? '/spc.exe' : '/spc'), 0o755);

                if (!@rename($staging, $directory) && !is_file($executable)) {
                    throw new RuntimeException(sprintf('Unable to install static-php-cli into %s.', $directory));
                }
            } catch (RuntimeException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                throw new RuntimeException(
                    sprintf('Unable to unpack static-php-cli %s.', self::VERSION),
                    0,
                    $throwable,
                );
            } finally {
                Directory::remove($staging);
            }
        }

        $environment = [
            'BUILD_ROOT_PATH' => $job . '/buildroot',
            'DOWNLOAD_PATH'   => $home . '/downloads',
            'PKG_ROOT_PATH'   => $home . '/pkgroot/' . $platform,
            'SOURCE_PATH'     => $job . '/source',
        ];

        if (isset(self::ZIG_ASSETS[$platform])) {
            // An explicit architecture builds for its baseline CPU; a native target needs the features of this one.
            $environment['SPC_TARGET'] = substr($platform, strlen('linux-')) . '-linux-musl';
            $this->installZig($executable, $platform, $job, $environment, $output);
        }

        $pkgConfig = $environment['PKG_ROOT_PATH'] . '/bin/pkg-config';

        if (!str_starts_with($platform, 'windows-') && !is_executable($pkgConfig)) {
            $status = ProcessRunner::run([
                $executable,
                'install-pkg',
                'pkg-config',
            ], $job, $output, sprintf('static-php-cli %s', self::VERSION), $environment);

            if (0 !== $status) {
                throw new RuntimeException(sprintf(
                    'static-php-cli could not install pkg-config (exit status %d).',
                    $status,
                ));
            }
        }

        return ProcessRunner::run([
            $executable,
            ...$arguments,
        ], $job, $output, sprintf('static-php-cli %s', self::VERSION), $environment);
    }

    /**
     * Installs the pinned Zig release into the package root through static-php-cli, which adds its compiler wrappers,
     * unless that release is installed already.
     *
     * @param array<string, string> $environment
     *
     * @throws RuntimeException When Zig cannot be downloaded, verified, or installed
     */
    private function installZig(
        string          $executable,
        string          $platform,
        string          $job,
        array           $environment,
        OutputInterface $output,
    ): void
    {
        $directory = $environment['PKG_ROOT_PATH'] . '/zig';
        $installed = true;

        foreach (self::ZIG_FILES as $file) {
            $installed = $installed && is_file($directory . '/' . $file);
        }

        if ($installed) {
            exec(escapeshellarg($directory . '/zig') . ' version 2>/dev/null', $version, $status);

            if (0 === $status && self::ZIG === ($version[0] ?? null)) {
                return;
            }
        }

        [$asset, $digest] = self::ZIG_ASSETS[$platform];
        $archive = $job . '/' . $asset;
        $package = 'zig-' . substr($platform, strlen('linux-')) . '-linux';
        Directory::remove($directory);
        $this->zigMirrors->download(self::ZIG, $asset, $archive, $digest);

        try {
            $status = ProcessRunner::run([
                $executable,
                'install-pkg',
                $package,
                '--custom-url=' . $package . ':file://' . $archive,
            ], $job, $output, sprintf('static-php-cli %s', self::VERSION), $environment);
        } finally {
            @unlink($archive);
        }

        if (0 !== $status) {
            throw new RuntimeException(sprintf(
                'static-php-cli could not install Zig %s (exit status %d).',
                self::ZIG,
                $status,
            ));
        }
    }
}
