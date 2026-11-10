<?php

declare(strict_types=1);

namespace Src\Project;

use JsonException;
use Symfony\Component\Console\Exception\RuntimeException;

use function file_get_contents;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Decodes a project's JSON file into PHP arrays, or objects on request, and scalars.
 */
final class JsonFile
{
    /**
     * @throws RuntimeException When the file cannot be read or does not contain valid JSON
     */
    public static function decode(string $path, bool $associative = true): mixed
    {
        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new RuntimeException(sprintf('Unable to read %s.', $path));
        }

        try {
            return json_decode($contents, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('%s does not contain valid JSON.', $path), 0, $exception);
        }
    }
}
