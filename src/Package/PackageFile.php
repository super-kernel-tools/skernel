<?php

declare(strict_types=1);

namespace Src\Package;

use Src\Filesystem\Directory;
use Symfony\Component\Console\Exception\RuntimeException;

use function chmod;
use function dirname;
use function fclose;
use function fopen;
use function is_file;
use function rename;
use function sprintf;
use function stream_copy_to_stream;
use function tempnam;
use function unlink;

/**
 * Writes a package, the concatenation of its parts, through a temporary file beside it and makes it executable.
 */
final class PackageFile
{
    /**
     * @param non-empty-list<string> $parts Files written in order, such as a micro runtime followed by a PHAR
     *
     * @throws RuntimeException When the output directory, a part, or the package cannot be written or read
     */
    public static function write(string $target, array $parts): void
    {
        Directory::create(dirname($target));
        $temporary = @tempnam(dirname($target), '.skernel-');

        if (false === $temporary) {
            throw new RuntimeException(sprintf('Unable to write the package %s.', $target));
        }

        try {
            $destination = @fopen($temporary, 'wb');

            if (false === $destination) {
                throw new RuntimeException(sprintf('Unable to write the package %s.', $target));
            }

            foreach ($parts as $part) {
                $source = @fopen($part, 'rb');

                if (false === $source || false === stream_copy_to_stream($source, $destination)) {
                    throw new RuntimeException(sprintf('Unable to read %s into the package.', $part));
                }

                fclose($source);
            }

            fclose($destination);

            if (!@chmod($temporary, 0o755) || !@rename($temporary, $target)) {
                throw new RuntimeException(sprintf('Unable to write the package %s.', $target));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
