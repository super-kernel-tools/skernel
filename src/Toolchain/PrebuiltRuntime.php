<?php

declare(strict_types=1);

namespace Src\Toolchain;

use PharData;
use Src\Filesystem\Directory;
use Src\Project\JsonFile;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

use function basename;
use function chmod;
use function dirname;
use function hash_file;
use function is_array;
use function is_file;
use function preg_match;
use function rename;
use function sprintf;
use function uniqid;

/**
 * Installs a managed runtime that the SKERNEL runtimes repository publishes prebuilt, so that it need not be built.
 *
 * A runtime is published in the release `php-<version>` as `php-<version>-<platform>-<key>.tar.gz`, the key being the
 * name of the runtime's directory, beside a `.sha256` file with the archive's digest. The archive holds the PHP CLI,
 * the micro runtime, and the manifest of their build. It is installed only when the archive matches the published
 * digest, the manifest names the requested version, platform, and extensions, both binaries match the digests the
 * manifest records, and the PHP runs here and reports the versions that the project accepts.
 */
final readonly class PrebuiltRuntime
{
    private const string RELEASES = 'https://github.com/super-kernel-tools/runtimes/releases/download/';

    /** @param string $releases The address that the release tags follow, which ends with a slash */
    public function __construct(
        private Home              $home,
        private ExtensionVersions $extensionVersions,
        private string            $releases = self::RELEASES,
    )
    {
    }

    /**
     * @param Runtime $runtime The runtime to install, which names its version and the files of its directory
     *
     * @return bool Whether the runtime was installed; false when none is published for it or the releases cannot be
     *              reached
     *
     * @throws RuntimeException When a published runtime cannot be downloaded, does not match its digest or its
     *                          manifest, does not run here, has an extension version the project does not accept, or
     *                          cannot be installed
     */
    public function install(Runtime $runtime, ExtensionRequirements $extensions, OutputInterface $output): bool
    {
        $directory = dirname($runtime->php);
        $platform = Platform::current();
        $archive = sprintf('php-%s-%s-%s.tar.gz', $runtime->version, $platform, basename($directory));
        $url = $this->releases . 'php-' . $runtime->version . '/' . $archive;

        try {
            $published = Download::contents($url . '.sha256');
        } catch (RuntimeException) {
            return false;
        }

        if (1 !== preg_match('/\A([0-9a-f]{64})(?:\s|\z)/', $published, $digest)) {
            throw new RuntimeException(sprintf('%s.sha256 holds no SHA-256 digest.', $url));
        }

        $output->writeln(
            sprintf('Downloading the prebuilt PHP %s runtime for %s.', $runtime->version, $platform),
            OutputInterface::OUTPUT_RAW,
        );
        $job = $this->home->directory() . '/work/' . uniqid('prebuilt-', true);
        $staged = $job . '/runtime';
        Directory::create($staged);

        try {
            Download::file($url, $job . '/' . $archive, $digest[1]);
            $this->unpack($job . '/' . $archive, $staged, $runtime, $platform, $extensions);
            Directory::create(dirname($directory));

            if (!@rename($staged, $directory) && !is_file($directory . '/manifest.json')) {
                throw new RuntimeException(sprintf('Unable to publish the runtime to %s.', $directory));
            }
        } finally {
            Directory::remove($job);
        }

        return true;
    }

    /**
     * Unpacks the three files of a runtime and checks them against the request before anything uses them.
     *
     * @throws RuntimeException When the archive cannot be unpacked, or its runtime is not the requested one, does not
     *                          run here, or has an extension version the project does not accept
     */
    private function unpack(
        string                $archive,
        string                $staged,
        Runtime               $runtime,
        string                $platform,
        ExtensionRequirements $extensions,
    ): void
    {
        $php = basename($runtime->php);
        $micro = basename($runtime->micro);

        try {
            new PharData($archive)->extractTo($staged, [
                $php,
                $micro,
                'manifest.json',
            ], true);
        } catch (Throwable $throwable) {
            throw new RuntimeException(sprintf('Unable to unpack the prebuilt runtime %s.', $archive), 0, $throwable);
        }

        $manifest = JsonFile::decode($staged . '/manifest.json');
        $sha256 = is_array($manifest) && is_array($manifest['sha256'] ?? null) ? $manifest['sha256'] : [];

        if (
            !is_array($manifest)
            || $runtime->version !== ($manifest['php'] ?? null)
            || $platform !== ($manifest['platform'] ?? null)
            || $extensions->names !== ($manifest['extensions'] ?? null)
            || hash_file('sha256', $staged . '/' . $php) !== ($sha256[$php] ?? null)
            || hash_file('sha256', $staged . '/' . $micro) !== ($sha256[$micro] ?? null)
        ) {
            throw new RuntimeException(sprintf(
                'The prebuilt runtime %s does not match its manifest or the requested PHP %s for %s.',
                basename($archive),
                $runtime->version,
                $platform,
            ));
        }

        chmod($staged . '/' . $php, 0o755);
        chmod($staged . '/' . $micro, 0o755);
        $process = new Process([
            $staged . '/' . $php,
            '-n',
            '-r',
            'echo PHP_VERSION;',
        ]);
        $process->run();

        if (!$process->isSuccessful() || $runtime->version !== $process->getOutput()) {
            throw new RuntimeException(sprintf(
                'The prebuilt PHP %s runtime does not run on this machine; set "prebuilt" to false in skernel.json '
                . 'to build it here.',
                $runtime->version,
            ));
        }

        $this->extensionVersions->verify($staged . '/' . $php, $extensions);
    }
}
