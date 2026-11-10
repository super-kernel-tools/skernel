<?php

declare(strict_types=1);

namespace Src\Update;

use Src\Toolchain\Download;
use Src\Toolchain\Platform;
use Symfony\Component\Console\Exception\RuntimeException;

use function chmod;
use function dirname;
use function fileperms;
use function is_array;
use function is_file;
use function is_string;
use function is_writable;
use function json_decode;
use function rename;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;

/**
 * Replaces the running SKERNEL binary with the one of the latest GitHub Release.
 *
 * A release has one asset per platform, named `skernel-<platform>`, or `skernel-<platform>.exe` on Windows, and the
 * digest that GitHub reports for the asset verifies the download. Any version other than the latest release's is
 * replaced. A micro runtime reads only the appended PHAR of its own file, so the binary cannot hash itself to compare.
 */
final class SelfUpdater
{
    private const string LATEST_RELEASE = 'https://api.github.com/repos/super-kernel/skernel/releases/latest';

    private const string DIGEST_PREFIX = 'sha256:';

    /**
     * @param string $version The version of the running binary
     *
     * @return string|null The tag of the installed release, or null when the binary is up to date already
     *
     * @throws RuntimeException When the latest release cannot be read or has no verified binary for this platform, or
     *                          the binary cannot be replaced
     */
    public function update(string $binary, string $version): ?string
    {
        try {
            $release = json_decode(Download::contents(self::LATEST_RELEASE), true);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('Unable to read the latest SKERNEL release from GitHub.', 0, $exception);
        }

        return $this->install($binary, $version, is_array($release) ? $release : []);
    }

    /**
     * @param string                  $version The version of the running binary
     * @param array<array-key, mixed> $release A release as the GitHub API describes it
     *
     * @return string|null The tag of the installed release, or null when the binary is up to date already
     *
     * @throws RuntimeException When the release has no verified binary for this platform, or the binary cannot be
     *                          replaced
     */
    public function install(string $binary, string $version, array $release): ?string
    {
        $platform = Platform::current();
        $name = 'skernel-' . $platform . (str_starts_with($platform, 'windows-') ? '.exe' : '');
        $tag = is_string($release['tag_name'] ?? null) ? $release['tag_name'] : 'unknown';

        if ($tag === $version) {
            return null;
        }

        $asset = null;

        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $candidate) {
            if (is_array($candidate) && $name === ($candidate['name'] ?? null)) {
                $asset = $candidate;
            }
        }

        if (null === $asset) {
            throw new RuntimeException(sprintf('The SKERNEL release %s has no binary named %s.', $tag, $name));
        }

        $digest = $asset['digest'] ?? null;
        $url = $asset['browser_download_url'] ?? null;

        if (!is_string($url) || !is_string($digest) || !str_starts_with($digest, self::DIGEST_PREFIX)) {
            throw new RuntimeException(
                sprintf('The SKERNEL release %s reports no SHA-256 digest for %s.', $tag, $name),
            );
        }

        if (!is_writable(dirname($binary))) {
            throw new RuntimeException(sprintf('Unable to replace %s.', $binary));
        }

        $staged = $binary . '.update';

        try {
            Download::file($url, $staged, substr($digest, strlen(self::DIGEST_PREFIX)));

            if (!@chmod($staged, fileperms($binary) & 0o777) || !@rename($staged, $binary)) {
                throw new RuntimeException(sprintf('Unable to replace %s.', $binary));
            }
        } finally {
            if (is_file($staged)) {
                @unlink($staged);
            }
        }

        return $tag;
    }
}
