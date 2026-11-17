<?php

declare(strict_types=1);

namespace Modules\Analyzers\Filament;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\Analyzers\Actions\ManageMachines;

class ListMachines extends ListRecords
{
    protected static string $resource = MachineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')->label('Add a machine')
                ->schema(fn (): array => MachineResource::fields())
                ->action(function (Action $action, array $data): void {
                    $made = Guard::run($action, fn () => app(ManageMachines::class)->add($data), 'Machine added');

                    if ($made !== null) {
                        MachineResource::showKey($made[1]);
                    }
                }),
        ];
    }
}
