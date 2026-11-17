<?php

declare(strict_types=1);

namespace Modules\Imaging\Filament;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Models\Service;
use Modules\Imaging\Actions\AddStarterTemplates;
use Modules\Imaging\Permissions;
use Modules\Imaging\Support\StarterTemplates;

class ManageTemplates extends ManageRecords
{
    protected static string $resource = TemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('starter')->label('Add example templates')->color('gray')
                ->visible(fn (): bool => Gate::allows(Permissions::TEMPLATES_MANAGE))
                ->modalDescription('Example texts to start from. They are examples only: your reporting doctor must read and correct each one before use.')
                ->schema([
                    Select::make('service_id')->label('For the imaging test')->required()->searchable()->options(fn (): array => TemplateResource::imagingServices()),
                    Select::make('set')->label('Examples')->required()->native(false)->options(StarterTemplates::options()),
                ])
                ->action(function (Action $action, array $data): void {
                    Guard::run($action, fn () => app(AddStarterTemplates::class)->handle(Service::findOrFail($data['service_id']), (string) $data['set']), 'Example templates added');
                }),
            CreateAction::make()->label('New template')->modalWidth('5xl'),
        ];
    }
}
