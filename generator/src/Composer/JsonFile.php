<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Composer;

use JsonException;
use SKERNEL\Generator\Exception\GeneratorException;
use stdClass;

use function file_get_contents;
use function is_file;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Decodes a Composer JSON file whose top level is an object.
 *
 * @internal
 */
final class JsonFile
{
    /**
     * @throws GeneratorException When the file is missing, unreadable, malformed, or not a JSON object
     */
    public static function decode(string $path): stdClass
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;

        if (false === $contents) {
            throw new GeneratorException(sprintf('Unable to read %s.', $path));
        }

        try {
            $document = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GeneratorException(
                sprintf('Invalid JSON in %s: %s', $path, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (!$document instanceof stdClass) {
            throw new GeneratorException(sprintf('%s must contain a JSON object.', $path));
        }

        return $document;
    }
}
