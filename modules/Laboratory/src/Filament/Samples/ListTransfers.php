<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Samples;

use App\Core\Branches\Branch;
use App\Core\Branches\CurrentBranch;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;
use Modules\Laboratory\Actions\HandleSample;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Filament\Concerns;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Permissions;
use Modules\Laboratory\Support\LabRoutes;

class ListTransfers extends ListRecords
{
    protected static string $resource = TransferResource::class;

    /** Collected samples of this branch waiting to go to its laboratory. */
    private function waiting(): int
    {
        $branch = app(CurrentBranch::class)->get();
        $lab = app(LabRoutes::class)->labFor($branch->id);

        return $lab === $branch->id ? 0 : Sample::where('branch_id', $branch->id)->where('lab_branch_id', $lab)->where('status', SampleStatus::Collected->value)->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label(fn (): string => 'Send samples ('.$this->waiting().')')
                ->visible(fn (): bool => $this->waiting() > 0 && Gate::allows(Permissions::SAMPLES_COLLECT, app(CurrentBranch::class)->get()->id))
                ->modalDescription(function (): string {
                    $branch = app(CurrentBranch::class)->get();
                    $lab = Branch::find(app(LabRoutes::class)->labFor($branch->id));

                    return "Every collected sample of {$branch->name} goes to {$lab?->name} as one transfer. Print the courier list and put it in the box.";
                })
                ->schema([TextInput::make('note')->label('Note for the laboratory')->maxLength(255)->placeholder('Courier name, box number')])
                ->action(function (array $data): void {
                    $branch = app(CurrentBranch::class)->get();
                    $lab = Branch::findOrFail(app(LabRoutes::class)->labFor($branch->id));
                    Concerns::attempt(fn () => app(HandleSample::class)->send($branch, $lab, null, $data['note'] ?? null), 'Samples sent');
                }),
        ];
    }
}
