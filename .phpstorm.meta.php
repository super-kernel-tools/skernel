<?php

declare(strict_types=1);

namespace PHPSTORM_META {
    use Psr\Container\ContainerInterface;

    use function map;
    use function override;

    // Reflect
    override(ContainerInterface::get(0), map([
        '' => '@',
    ]));
}
