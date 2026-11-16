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

    // Modules\<Name>\Tests\<Rest> lives in modules/<Name>/tests/<Rest>.php.
    [$folder, $rest] = str_starts_with($parts[2], 'Tests\\')
        ? ['tests', substr($parts[2], 6)]
        : ['src', $parts[2]];

    $file = dirname(__DIR__, 3).'/modules/'.$parts[1].'/'.$folder.'/'.str_replace('\\', '/', $rest).'.php';

    if (is_file($file)) {
        require $file;
    }
});
