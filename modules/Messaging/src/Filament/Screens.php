<?php

declare(strict_types=1);

namespace Modules\Messaging\Filament;

/**
 * The screens this module adds to the panel.
 */
final class Screens
{
    /** @return list<class-string> */
    public static function resources(): array
    {
        return [MessageResource::class, TemplateResource::class];
    }

    /** @return list<class-string> */
    public static function pages(): array
    {
        return [MessagingSettings::class];
    }
}
