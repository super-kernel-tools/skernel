<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Composer;

use function preg_match;

/**
 * Classifies lowercase Composer requirement names as installable packages or platform packages.
 *
 * @internal
 */
final class PackageName
{
    private const string PACKAGE_PATTERN
        = '~\\A[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*\\z~';

    private const string PLATFORM_PATTERN
        = '~\\A(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*'
        . '|composer(?:-(?:plugin|runtime)-api)?)\\z~';

    public static function isPackage(string $name): bool
    {
        return 1 === preg_match(self::PACKAGE_PATTERN, $name);
    }

    public static function isPlatform(string $name): bool
    {
        return 1 === preg_match(self::PLATFORM_PATTERN, $name);
    }
}
