<?php

declare(strict_types=1);

namespace Src\Build;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Src\Filesystem\Directory;
use Src\Toolchain\Home;
use Symfony\Component\Console\Exception\RuntimeException;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function hash_final;
use function hash_init;
use function hash_update;
use function is_file;
use function ksort;
use function rename;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function uniqid;

use const SORT_STRING;

/**
 * Locates the generator that a project's child process runs: SKERNEL's own `generator/` directory from source, or a
 * copy extracted to the SKERNEL home from a PHAR, because another process cannot read a standalone binary's PHAR.
 */
final readonly class GeneratorLocator
{
    public function __construct(private Home $home)
    {
    }

    /**
     * @return string The directory that holds `generate.php`
     *
     * @throws RuntimeException When the generator cannot be extracted from the PHAR
     */
    public function directory(): string
    {
        $source = dirname(__DIR__, 2) . '/generator';

        return str_starts_with($source, 'phar://') ? $this->extract($source) : $source;
    }

    /**
     * Copies the generator to `generator/<sha256 of its files>/` below the SKERNEL home, assembled in a job below
     * `work/` and published by atomic rename, and reuses a copy with the same digest.
     *
     * @return string The extracted directory
     *
     * @throws RuntimeException When the generator cannot be read, or the copy cannot be written or published
     */
    public function extract(string $source): string
    {
        $files = [];

        /** @var SplFileInfo $entry */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        ) as $entry) {
            if ($entry->isFile()) {
                $contents = file_get_contents($entry->getPathname());

                if (false === $contents) {
                    throw new RuntimeException(sprintf('Unable to read %s.', $entry->getPathname()));
                }

                $files[substr($entry->getPathname(), strlen($source) + 1)] = $contents;
            }
        }

        ksort($files, SORT_STRING);
        $context = hash_init('sha256');

        foreach ($files as $path => $contents) {
            hash_update($context, $path . "\0" . strlen($contents) . "\0" . $contents);
        }

        $home = $this->home->directory();
        $directory = $home . '/generator/' . hash_final($context);

        if (is_file($directory . '/generate.php')) {
            return $directory;
        }

        $job = $home . '/work/' . uniqid('generator-', true);

        try {
            foreach ($files as $path => $contents) {
                Directory::create(dirname($job . '/' . $path));

                if (false === file_put_contents($job . '/' . $path, $contents)) {
                    throw new RuntimeException(sprintf('Unable to write %s/%s.', $job, $path));
                }
            }

            Directory::create(dirname($directory));

            if (!@rename($job, $directory) && !is_file($directory . '/generate.php')) {
                throw new RuntimeException(sprintf('Unable to publish the generator to %s.', $directory));
            }
        } finally {
            Directory::remove($job);
        }

        return $directory;
    }
}
