<?php

declare(strict_types=1);

namespace Src\Toolchain;

use Src\Project\Project;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides the managed PHP runtime that runs and packages a project: the requested or required version, built with
 * the extensions that the project's installation requires.
 */
final readonly class ManagedPhp
{
    public function __construct(private PhpReleases $releases, private RuntimeProvider $runtimes)
    {
    }

    /**
     * @param string|null $requested A version such as 8.4 or 8.4.27, or null for the project's PHP requirement
     *
     * @throws RuntimeException When the version cannot be resolved or the runtime cannot be provided
     */
    public function runtime(Project $project, ?string $requested, OutputInterface $output): Runtime
    {
        return $this->runtimes->provide(
            $this->releases->resolve($requested, $project->phpRequirement),
            Extensions::required($project),
            $output,
        );
    }
}
