<?php

declare(strict_types=1);

/*
| Autoloader for module classes.
|
| Maps Modules\<Name>\<Rest> to modules/<Name>/src/<Rest>.php, so a new
| module folder works without editing composer.json.
*/

spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'Modules\\')) {
        return;
    }

    $parts = explode('\\', $class, 3);

    if (count($parts) < 3) {
        return;
    }

    $file = dirname(__DIR__, 3).'/modules/'.$parts[1].'/src/'.str_replace('\\', '/', $parts[2]).'.php';

    if (is_file($file)) {
        require $file;
    }
});
