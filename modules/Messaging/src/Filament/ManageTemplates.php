<?php

declare(strict_types=1);

namespace Modules\Messaging\Filament;

use Filament\Resources\Pages\ManageRecords;
use Modules\Messaging\Support\Templates;

class ManageTemplates extends ManageRecords
{
    protected static string $resource = TemplateResource::class;

    public function mount(): void
    {
        parent::mount();

        // The built-in wording becomes rows the clinic can see and edit.
        app(Templates::class)->ensureDefaults();
    }
}
