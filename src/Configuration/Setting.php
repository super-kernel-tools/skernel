<?php

declare(strict_types=1);

namespace Src\Configuration;

use Src\Toolchain\PhpVersion;
use stdClass;
use Symfony\Component\Console\Exception\RuntimeException;

use function array_diff;
use function array_keys;
use function get_object_vars;
use function implode;
use function is_bool;
use function is_string;
use function sprintf;

/**
 * Validates the values of an SKERNEL configuration file and names the offending setting in its messages.
 */
final class Setting
{
    /**
     * @param list<string> $allowed
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the value is not an object or holds a setting outside the allowed ones
     */
    public static function object(mixed $value, string $file, string $key, array $allowed): array
    {
        if (!$value instanceof stdClass) {
            throw new RuntimeException('' === $key
                ? sprintf('%s must contain a JSON object.', $file)
                : sprintf('%s: "%s" must be an object.', $file, $key));
        }

        $settings = get_object_vars($value);
        $unknown = array_diff(array_keys($settings), $allowed);

        if ([] !== $unknown) {
            throw new RuntimeException(sprintf(
                '%s: unknown setting "%s".',
                $file,
                ('' === $key ? '' : $key . '.') . implode('", "', $unknown),
            ));
        }

        return $settings;
    }

    /** @throws RuntimeException When a present value is not a non-empty string */
    public static function string(mixed $value, string $file, string $key): ?string
    {
        if (null !== $value && (!is_string($value) || '' === $value)) {
            throw new RuntimeException(sprintf('%s: "%s" must be a non-empty string.', $file, $key));
        }

        return $value;
    }

    /** @throws RuntimeException When a present value is not a boolean */
    public static function boolean(mixed $value, string $file, string $key): ?bool
    {
        if (null !== $value && !is_bool($value)) {
            throw new RuntimeException(sprintf('%s: "%s" must be true or false.', $file, $key));
        }

        return $value;
    }

    /** @throws RuntimeException When a present value is not a PHP version such as 8.4 or 8.4.27 */
    public static function version(mixed $value, string $file, string $key): ?string
    {
        if (null !== $value && !PhpVersion::isValid($value)) {
            throw new RuntimeException(sprintf('%s: "%s" must be a PHP version such as 8.4 or 8.4.27.', $file, $key));
        }

        return $value;
    }
}
