<?php

// For use without Composer: require 'path/to/sdks/php/autoload.php';
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'SfuKit\\')) {
        $file = __DIR__ . '/src/' . substr($class, 7) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
