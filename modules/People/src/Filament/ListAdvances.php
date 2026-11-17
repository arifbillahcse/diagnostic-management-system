<?php

declare(strict_types=1);

namespace Modules\People\Filament;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;
use Modules\People\Actions\ManageAdvances;
use Modules\People\Models\Staff;
use Modules\People\Permissions;

class ListAdvances extends ListRecords
{
    protected static string $resource = AdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('give')->label('Give an advance')
                ->visible(fn (): bool => Gate::allows(Permissions::ADVANCES_MANAGE))
                ->schema(fn (): array => AdvanceResource::giveFields())
                ->action(fn (Action $action, array $data) => Guard::run($action, fn () => app(ManageAdvances::class)->give(
                    Staff::findOrFail($data['staff_id']), (string) $data['amount'], (string) $data['given_on'], (string) $data['reason'],
                    isset($data['account_id']) ? (int) $data['account_id'] : null,
                ), 'Advance recorded')),
        ];
    }
}
