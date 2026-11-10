<?php

declare(strict_types=1);

use SKERNEL\Generator\Entry\ChildProcess;

// Load every generator class from this directory before the project's autoloader could offer another copy of them.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'SKERNEL\\Generator\\')) {
        require __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 18)) . '.php';
    }
}, true, true);

foreach (
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/src', FilesystemIterator::SKIP_DOTS),
    ) as $file
) {
    $relative = substr($file->getPathname(), strlen(__DIR__) + 5, -4);
    class_exists('SKERNEL\\Generator\\' . str_replace('/', '\\', $relative));
}

exit(ChildProcess::run($argv));
