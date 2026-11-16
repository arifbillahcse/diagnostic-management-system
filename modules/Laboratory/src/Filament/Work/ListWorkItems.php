<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Work;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\WorkItem;

class ListWorkItems extends ListRecords
{
    protected static string $resource = WorkItemResource::class;

    protected static ?string $title = 'Worklist';

    public function getTabs(): array
    {
        $tab = fn (string $label, array $statuses): Tab => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', array_map(fn (WorkStatus $s): string => $s->value, $statuses)))
            ->badge(fn (): int => WorkItem::whereIn('status', array_map(fn (WorkStatus $s): string => $s->value, $statuses))->count());

        return [
            'enter' => $tab('To enter', [WorkStatus::Ready, WorkStatus::Amending]),
            'verify' => $tab('To verify', [WorkStatus::Entered]),
            'waiting' => $tab('Waiting for sample', [WorkStatus::AwaitingSample]),
            'released' => Tab::make('Released')->modifyQueryUsing(fn (Builder $query) => $query->where('status', WorkStatus::Released->value)->reorder('released_at', 'desc')),
            'all' => Tab::make('All'),
        ];
    }
}
