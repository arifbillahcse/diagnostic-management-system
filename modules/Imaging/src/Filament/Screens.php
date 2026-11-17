<?php

declare(strict_types=1);

namespace Modules\Imaging\Filament;

/**
 * The screens this module adds to the panel.
 */
final class Screens
{
    /** @return list<class-string> */
    public static function resources(): array
    {
        return [StudyResource::class, TemplateResource::class, PhraseResource::class];
    }

    /** @return list<class-string> */
    public static function pages(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public static function widgets(): array
    {
        return [ImagingStatusWidget::class];
    }
}
