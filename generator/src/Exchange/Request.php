<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Exchange;

use JsonException;
use SKERNEL\Generator\Exception\GeneratorException;

use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * What SKERNEL asks a generator child process to build, passed as a JSON file.
 *
 * @internal
 */
final readonly class Request
{
    /**
     * @param non-empty-string            $skernelVersion
     * @param array<class-string, string> $fatalClasses   Classes that ended an earlier run, with the fatal error message
     */
    public function __construct(
        public string $projectDirectory,
        public string $vendorDirectory,
        public string $skernelVersion,
        public array  $fatalClasses = [],
    )
    {
    }

    /**
     * @throws GeneratorException When the file cannot be written
     */
    public function write(string $path): void
    {
        try {
            $json = json_encode([
                'project' => $this->projectDirectory,
                'vendor'  => $this->vendorDirectory,
                'skernel' => $this->skernelVersion,
                'fatal'   => (object) $this->fatalClasses,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new GeneratorException('Unable to encode the generator request.', previous: $exception);
        }

        if (false === @file_put_contents($path, $json)) {
            throw new GeneratorException(sprintf('Unable to write the generator request %s.', $path));
        }
    }

    /**
     * @throws GeneratorException When the file is missing or not a request
     */
    public static function read(string $path): self
    {
        $contents = @file_get_contents($path);

        try {
            $request = false === $contents ? null : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $request = null;
        }

        if (
            !is_array($request)
            || !is_string($request['project'] ?? null)
            || !is_string($request['vendor'] ?? null)
            || !is_string($request['skernel'] ?? null)
            || '' === $request['skernel']
            || !is_array($request['fatal'] ?? null)
        ) {
            throw new GeneratorException(sprintf('Invalid generator request %s.', $path));
        }

        $fatalClasses = [];

        foreach ($request['fatal'] as $class => $message) {
            $fatalClasses[(string) $class] = (string) $message;
        }

        return new self($request['project'], $request['vendor'], $request['skernel'], $fatalClasses);
    }
}
