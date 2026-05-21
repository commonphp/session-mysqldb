<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$workspaceRoot = dirname($packageRoot, 3);
$autoloads = [
    $packageRoot . '/vendor/autoload.php',
    $workspaceRoot . '/vendor/autoload.php',
];
$autoload = null;

foreach ($autoloads as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    throw new RuntimeException(
        'Composer dependencies are not installed. Run `composer install` before running the test suite.',
    );
}

require $autoload;

spl_autoload_register(static function (string $class) use ($packageRoot): void {
    $prefixes = [
        'CommonPHP\\Drivers\\Session\\MySQL\\Tests\\' => $packageRoot . '/tests/',
        'CommonPHP\\Drivers\\Session\\MySQL\\' => $packageRoot . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDirectory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relativePath = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $baseDirectory . $relativePath . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});
