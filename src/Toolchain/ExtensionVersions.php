<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Composer\Semver\Semver;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

use function array_combine;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function preg_match_all;
use function sprintf;

/**
 * Keeps the extensions of a managed runtime within the versions that the project's installation accepts.
 *
 * static-php-cli downloads the latest release of an extension, whatever the project requires. For the extensions that
 * are published on PECL under the name static-php-cli uses, SKERNEL selects the latest stable release that the
 * installation accepts and has static-php-cli download that instead. Every other extension is only verified after the
 * build. The PECL release lists are cached in the SKERNEL home for a day.
 */
final readonly class ExtensionVersions
{
    private const array PECL = [
        'redis',
        'swoole',
    ];

    private const string RELEASES = 'https://pecl.php.net/rest/r/%s/allreleases.xml';

    private const string ARCHIVE = 'https://pecl.php.net/get/%s-%s.tgz';

    public function __construct(private DailyCache $cache)
    {
    }

    /**
     * @return array<string, string> The version to build by extension, for the extensions that have one
     *
     * @throws RuntimeException When PECL cannot be read and nothing is cached, or lists no accepted stable release
     */
    public function select(ExtensionRequirements $extensions): array
    {
        $versions = [];

        foreach ($extensions->versioned() as $name) {
            if (!in_array($name, self::PECL, true)) {
                continue;
            }

            $releases = $this->cache->get('pecl-releases', $name, static function () use ($name): array {
                $url = sprintf(self::RELEASES, $name);

                try {
                    preg_match_all('~<r><v>([^<]+)</v><s>stable</s></r>~', Download::contents($url), $matches);
                } catch (RuntimeException $exception) {
                    throw new RuntimeException(
                        sprintf('Unable to read the releases of %s from PECL.', $name),
                        0,
                        $exception,
                    );
                }

                return $matches[1];
            });

            foreach (is_array($releases) ? Semver::rsort($releases) : [] as $release) {
                if ($extensions->allows($name, $release)) {
                    $versions[$name] = $release;

                    continue 2;
                }
            }

            throw new RuntimeException(sprintf(
                'PECL lists no stable release of %s that the project accepts: %s.',
                $name,
                $extensions->describe($name),
            ));
        }

        return $versions;
    }

    public function archive(string $name, string $version): string
    {
        return sprintf(self::ARCHIVE, $name, $version);
    }

    /**
     * Reads the versions of the versioned extensions from a built PHP and checks them against the installation.
     *
     * An extension that reports no version that Composer can read cannot be checked and is left out.
     *
     * @return array<string, string> The version that the PHP reports by extension
     *
     * @throws RuntimeException When the PHP cannot report its versions, or an extension has a version that the
     *                          installation does not accept
     */
    public function verify(string $php, ExtensionRequirements $extensions): array
    {
        $names = $extensions->versioned();

        if ([] === $names) {
            return [];
        }

        $process = new Process([
            $php,
            '-n',
            '-r',
            'echo json_encode(array_map(phpversion(...), array_slice($argv, 1)));',
            '--',
            ...$names,
        ]);
        $process->run();
        $reported = $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;

        if (!is_array($reported) || count($reported) !== count($names)) {
            throw new RuntimeException(sprintf('Unable to read the extension versions of %s.', $php));
        }

        $versions = [];

        foreach (array_combine($names, $reported) as $name => $version) {
            if (!is_string($version)) {
                continue;
            }

            try {
                $accepted = $extensions->allows($name, $version);
            } catch (UnexpectedValueException) {
                continue;
            }

            if (!$accepted) {
                throw new RuntimeException(sprintf(
                    'static-php-cli built %s %s, which the project does not accept: %s.',
                    $name,
                    $version,
                    $extensions->describe($name),
                ));
            }

            $versions[$name] = $version;
        }

        return $versions;
    }
}
