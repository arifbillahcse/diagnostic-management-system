<?php

declare(strict_types=1);

namespace Modules\People\Filament;

use App\Core\Money\Money;
use App\Core\Tenancy\ClinicContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Models\Account;
use Modules\People\Actions\RunPayroll;
use Modules\People\Models\SalaryLine;
use Modules\People\Models\SalaryRun;
use Modules\People\Permissions;
use Modules\People\Support\AdvanceBook;
use Modules\People\Support\FinanceLink;

/**
 * One salary run: the people in it, their deductions and advances taken
 * back (editable while a draft), and the moves of its state machine.
 *
 * @property SalaryRun $record
 */
class ViewSalaryRun extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SalaryRunResource::class;

    protected string $view = 'people::salary-run';

    /** @var array<int|string, array{deduction: string, note: ?string, recovery: string}> */
    public array $edits = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(static::getResource()::canView($this->getRecord()), 403);
        $this->fillEdits();
    }

    public function getTitle(): string
    {
        return "Salaries {$this->record->monthLabel()} · {$this->record->run_no}";
    }

    /** @return Collection<int, SalaryLine> */
    public function getLinesProperty(): Collection
    {
        return $this->record->lines()->with('staff')->get()->sortBy(fn (SalaryLine $l) => $l->staff->name)->values();
    }

    /** @return array<int, Money> advance owed per staff id */
    public function getOwedProperty(): array
    {
        $book = app(AdvanceBook::class);

        return $this->lines->mapWithKeys(fn (SalaryLine $l): array => [$l->staff_id => $book->outstanding($l->staff_id)])->all();
    }

    /** @return list<string> */
    public function getNewAdvancesProperty(): array
    {
        return $this->record->isDraft() ? app(RunPayroll::class)->newAdvances($this->record) : [];
    }

    public function canEdit(): bool
    {
        return $this->record->isDraft() && Gate::allows(Permissions::PAYROLL_PREPARE, $this->record->branch_id);
    }

    public function saveLine(int $lineId): void
    {
        $line = $this->record->lines()->findOrFail($lineId);
        $edit = $this->edits[$lineId] ?? [];

        $saved = Guard::run(null, fn () => app(RunPayroll::class)->setLine($line, (string) ($edit['deduction'] ?? '0'), $edit['note'] ?? null, (string) ($edit['recovery'] ?? '0')), 'Line saved');

        if ($saved !== null) {
            $this->reload();
        }
    }

    private function reload(): void
    {
        $this->record->refresh();
        unset($this->lines, $this->owed, $this->newAdvances);
        $this->fillEdits();
    }

    private function fillEdits(): void
    {
        $this->edits = [];

        foreach ($this->record->lines()->get() as $line) {
            $this->edits[$line->id] = [
                'deduction' => $line->deduction->toDecimal(),
                'note' => $line->deduction_note,
                'recovery' => $line->advance_recovery->toDecimal(),
            ];
        }
    }

    protected function getHeaderActions(): array
    {
        $run = fn (): SalaryRun => $this->record;
        $can = fn (string $permission): bool => Gate::allows($permission, $this->record->branch_id);
        $finance = app(FinanceLink::class);

        return [
            Action::make('refresh')->label('Refresh from attendance')->color('gray')
                ->visible(fn (): bool => $run()->isDraft() && $can(Permissions::PAYROLL_PREPARE))
                ->requiresConfirmation()
                ->modalDescription('Salaries and days are read again. Deductions and advances you typed are kept.')
                ->action(function (): void {
                    Guard::run(null, fn () => app(RunPayroll::class)->refresh($this->record), 'Salary run refreshed');
                    $this->reload();
                }),

            Action::make('approve')->label('Approve')
                ->visible(fn (): bool => $run()->isDraft() && $can(Permissions::PAYROLL_APPROVE))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Net pay ৳ '.$run()->total_net->formatted().'. The lines and this month\'s attendance are locked.')
                ->action(function (Action $action): void {
                    Guard::run($action, fn () => app(RunPayroll::class)->approve($this->record), 'Salary run approved');
                    $this->reload();
                }),

            Action::make('pay')->label('Mark paid')
                ->visible(fn (): bool => $run()->status === 'approved' && $can(Permissions::PAYROLL_PAY))
                ->modalDescription(fn (): string => $finance->on()
                    ? '৳ '.$run()->total_net->formatted().' is recorded in Finance as one expense, "Salaries".'
                    : 'The finance module is off, so only the payment date is kept.')
                ->fillForm(fn (): array => ['date' => now(app(ClinicContext::class)->timezone())->toDateString()])
                ->schema(fn (): array => array_values(array_filter([
                    DatePicker::make('date')->label('Paid on')->required()->native(false),
                    $finance->on() && $run()->total_net->isPositive()
                        ? Select::make('account_id')->label('Paid from')->required()->native(false)
                            ->options(fn (): array => Account::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        : null,
                ])))
                ->action(function (Action $action, array $data): void {
                    Guard::run($action, fn () => app(RunPayroll::class)->pay($this->record, (string) $data['date'], isset($data['account_id']) ? (int) $data['account_id'] : null), 'Salary run marked paid');
                    $this->reload();
                }),

            Action::make('payslips')->label('Print payslips')->color('gray')
                ->visible(fn (): bool => $run()->isSettled())
                ->url(fn (): string => route('people.print.payslips', $run()->public_id), shouldOpenInNewTab: true),

            Action::make('cancel')->label('Cancel run')->color('danger')
                ->visible(fn (): bool => ($run()->isDraft() && $can(Permissions::PAYROLL_PREPARE)) || ($run()->status === 'approved' && $can(Permissions::PAYROLL_APPROVE)))
                ->modalDescription('The month is opened again, and any advance this run took back is owed again.')
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(function (Action $action, array $data): void {
                    Guard::run($action, fn () => app(RunPayroll::class)->cancel($this->record, (string) $data['reason']), 'Salary run cancelled');
                    $this->reload();
                }),
        ];
    }
}
