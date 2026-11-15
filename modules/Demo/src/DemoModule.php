<?php

declare(strict_types=1);

namespace Modules\Demo;

use App\Core\Modules\Module;
use Modules\Demo\Filament\DemoNoteResource;
use Modules\Demo\Filament\DemoNotesWidget;

/**
 * Entry class of the demo module. Copy this folder as the starting point
 * for a real module.
 */
final class DemoModule extends Module
{
    public const NOTES_MANAGE = 'demo.notes.manage';

    public function permissions(): array
    {
        return [
            self::NOTES_MANAGE => 'Create, edit, and delete demo notes',
        ];
    }

    public function defaultGrants(): array
    {
        return [
            'admin' => [self::NOTES_MANAGE],
        ];
    }

    public function resources(): array
    {
        return [DemoNoteResource::class];
    }

    public function widgets(): array
    {
        return [DemoNotesWidget::class];
    }
}
