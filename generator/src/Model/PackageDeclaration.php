<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Model;

use stdClass;

/**
 * What the root composer.json or one composer.lock entry declares about a package.
 *
 * @internal
 */
final readonly class PackageDeclaration
{
    /**
     * @param string            $name         Lowercase Composer name
     * @param string|null       $version      Exactly as declared; null when the root omits it
     * @param string|null       $reference    The dist reference, else the source reference; null for the root
     * @param string            $composerType The declared type, `library` when omitted
     * @param bool              $development  Whether the entry comes from the lock's packages-dev
     * @param list<Requirement> $requirements Platform requirements sorted by name
     */
    public function __construct(
        public string    $name,
        public ?string   $version,
        public ?string   $reference,
        public string    $composerType,
        public bool      $root,
        public bool      $development,
        public ?stdClass $autoload,
        public ?stdClass $autoloadDev,
        public array     $requirements,
    )
    {
    }
}
