#!/usr/bin/env php
<?php

declare(strict_types=1);

use SKERNEL\Generator\Entry\SelfBuild;
use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\ApplicationInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

// From source, SKERNEL generates the build artifacts its container restores, and another start from the same tree
// waits until they are restored; a PHAR carries them from its build.
$lock = '' === Phar::running(false) ? SelfBuild::run(dirname(__DIR__)) : null;
$container = ApplicationContext::getContainer();
$lock?->release();

// Isolate the application run in an immediately invoked static closure; never requires the flow to exit or throw.
(static fn(): never => $container->get(ApplicationInterface::class)->run())();
