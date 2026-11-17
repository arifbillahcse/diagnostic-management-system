<?php

declare(strict_types=1);

namespace Modules\Analyzers\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Modules\Analyzers\Actions\ManageMachines;
use Modules\Analyzers\Models\Message;
use Modules\Analyzers\Permissions;

/**
 * One machine message: each value and what became of it, the raw text as
 * it arrived, and matching values that did not find their sample.
 *
 * @property Message $record
 */
class ViewMessage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MessageResource::class;

    protected string $view = 'analyzers::message';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getTitle(): string
    {
        return 'Message from '.$this->record->machine?->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('match')->label('Match to a sample')
                ->visible(fn (): bool => in_array($this->record->status, ['partial', 'unmatched'], true) && Gate::allows(Permissions::INBOX_RESOLVE))
                ->modalDescription('For values that did not find their sample, for example when the barcode was read wrongly. Values already saved are left alone.')
                ->schema([TextInput::make('sample')->label('Sample number (from the label)')->required()->maxLength(60)])
                ->action(function (Action $action, array $data): void {
                    Guard::run($action, fn () => app(ManageMachines::class)->rematch($this->record, (string) $data['sample']), 'Matched again');
                    $this->record->refresh();
                }),
        ];
    }
}
