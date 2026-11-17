<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Work;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Modules\Laboratory\Actions\EnterResults;
use Modules\Laboratory\Actions\VerifyResults;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Filament\Concerns;
use Modules\Laboratory\Models\WorkItem;
use Modules\Laboratory\Permissions;
use Modules\Laboratory\Support\Decimal;
use Modules\Laboratory\Support\ResultBuilder;

/**
 * Result entry and review for one test.
 *
 * @property WorkItem $record
 */
class ResultsPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = WorkItemResource::class;

    protected string $view = 'laboratory::results';

    /** @var array<int, array{value: ?string, comment: ?string}> */
    public array $values = [];

    /** The lock_version the form was loaded with. */
    public int $lockVersion = 0;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
        abort_if($this->record->status === WorkStatus::AwaitingSample, 404);

        $this->load();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->test_name;
    }

    public function getBreadcrumb(): string
    {
        return 'Results';
    }

    /** Refill the form from what is stored. */
    public function load(): void
    {
        $this->record->refresh();
        $stored = $this->record->results()->get()->keyBy('analyte_id');
        $this->values = [];

        foreach ($this->record->labTest->analytes as $analyte) {
            $result = $stored->get($analyte->id);
            $numeric = $result?->value_numeric !== null ? Decimal::micro($result->value_numeric) : null;

            $this->values[$analyte->id] = [
                // A number goes back into the box exactly as it was entered,
                // not as it is rounded for print.
                'value' => $result === null ? null : ($numeric !== null ? Decimal::plain($numeric) : $result->value_text),
                'comment' => $result?->comment,
            ];
        }

        $this->lockVersion = $this->record->lock_version;
    }

    /** @return list<array<string, mixed>> */
    public function getRowsProperty(): array
    {
        $stored = $this->record->results()->get()->keyBy('analyte_id');
        $builder = app(ResultBuilder::class);
        $collectedOn = $this->record->sample?->collected_at ?? now();
        $rows = [];

        foreach ($this->record->labTest->analytes()->with('ranges')->get() as $analyte) {
            $result = $stored->get($analyte->id);

            $rows[] = [
                'analyte' => $analyte,
                'result' => $result,
                'group' => $analyte->pivot->group_label,
                'required' => (bool) $analyte->pivot->is_required,
                'range' => $result?->ref_text ?? $builder->rangePreview($analyte, $this->record->patient, $collectedOn),
            ];
        }

        return $rows;
    }

    public function getEditableProperty(): bool
    {
        return $this->record->status->isEditable() && Gate::allows(Permissions::RESULTS_ENTER, $this->record->labBranchId());
    }

    public function save(): void
    {
        $this->store(false);
    }

    public function submit(): void
    {
        $this->store(true);
    }

    private function store(bool $submit): void
    {
        $done = Concerns::attempt(
            fn () => app(EnterResults::class)->handle($this->record, $this->values, $this->lockVersion, $submit),
            $submit ? 'Submitted for verification' : 'Draft saved',
            'Not saved',
        );

        if ($done) {
            $this->load();
        }
    }

    private function can(string $permission): bool
    {
        return Gate::allows($permission, $this->record->labBranchId());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label('Verify and release')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === WorkStatus::Entered && $this->can(Permissions::RESULTS_VERIFY))
                ->requiresConfirmation()
                ->modalHeading('Verify these results?')
                ->modalDescription('The results are locked and the report can be printed for the patient. Your name goes on the report.')
                ->action(function (): void {
                    Concerns::attempt(fn () => app(VerifyResults::class)->verify($this->record), 'Results verified and released');
                    $this->load();
                }),

            Action::make('sendBack')
                ->label('Send back')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === WorkStatus::Entered && $this->can(Permissions::RESULTS_VERIFY))
                ->schema([Textarea::make('reason')->label('What needs checking?')->required()->rows(2)->maxLength(240)])
                ->action(function (array $data): void {
                    Concerns::attempt(fn () => app(VerifyResults::class)->sendBack($this->record, $data['reason']), 'Sent back to the technician');
                    $this->load();
                }),

            Action::make('amend')
                ->label('Amend')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === WorkStatus::Released && $this->can(Permissions::RESULTS_AMEND))
                ->modalDescription('The released report stays on record. Until the corrected results are verified, this test cannot be printed.')
                ->schema([Textarea::make('reason')->label('Why is this result being corrected?')->required()->rows(2)->maxLength(255)])
                ->action(function (array $data): void {
                    Concerns::attempt(fn () => app(VerifyResults::class)->startAmendment($this->record, $data['reason']), 'Reopened for amendment');
                    $this->load();
                }),
        ];
    }
}
