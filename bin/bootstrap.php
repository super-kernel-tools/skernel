#!/usr/bin/env php
<?php

declare(strict_types=1);

use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\ApplicationInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

// Isolate startup in an immediately invoked static closure; never requires the flow to exit or throw.
(static fn(): never => ApplicationContext::getContainer()->get(ApplicationInterface::class)->run())();
