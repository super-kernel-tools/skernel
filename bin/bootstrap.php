#!/usr/bin/env php
<?php

declare(strict_types=1);

use SKERNEL\Generator\Entry\SelfBuild;
use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\ApplicationInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

// From source, SKERNEL generates the build artifacts its container restores; a PHAR carries them from its build.
if ('' === Phar::running(false)) {
    SelfBuild::run(dirname(__DIR__));
}

// Isolate startup in an immediately invoked static closure; never requires the flow to exit or throw.
(static fn(): never => ApplicationContext::getContainer()->get(ApplicationInterface::class)->run())();
