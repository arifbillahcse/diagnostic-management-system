<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Clinics;

use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicStatus;
use App\Core\Tenancy\Plan;
use App\Platform\Actions\ManageClinic;
use App\Platform\Billing\Billing;
use App\Platform\ClinicUsage;
use App\Platform\Filament\Resources\Invoices\InvoiceResource;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One clinic: plan, subscription, usage, and its history, with the
 * platform's actions.
 *
 * @property Clinic $record
 */
class ViewClinic extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ClinicResource::class;

    protected string $view = 'platform.clinic';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return $this->record->name;
    }

    /** @return array<string, mixed> */
    public function getUsageProperty(): array
    {
        return app(ClinicUsage::class)->for($this->record);
    }

    /** @return list<object> */
    public function getHistoryProperty(): array
    {
        $log = DB::connection('control')->table('provisioning_log')->where('clinic_id', $this->record->id)
            ->orderByDesc('id')->limit(30)->get(['created_at', 'step', 'status', 'message'])
            ->map(fn ($r) => (object) ['at' => $r->created_at, 'what' => $r->step.': '.$r->status, 'detail' => $r->message, 'who' => 'system']);

        $audit = DB::connection('control')->table('platform_audit')->leftJoin('platform_users', 'platform_users.id', '=', 'platform_audit.platform_user_id')
            ->where('clinic_id', $this->record->id)->orderByDesc('platform_audit.id')->limit(30)
            ->get(['platform_audit.created_at', 'action', 'details', 'platform_users.name as who'])
            ->map(fn ($r) => (object) ['at' => $r->created_at, 'what' => $r->action, 'detail' => $r->details, 'who' => $r->who ?? '-']);

        return $log->concat($audit)->sortByDesc('at')->take(30)->values()->all();
    }

    private function run(?Action $action, Closure $work, string $done): void
    {
        try {
            $work();
            Notification::make()->success()->title($done)->send();
            $this->record = $this->record->fresh();
            unset($this->usage, $this->history);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
            $action?->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        $manage = fn (): ManageClinic => app(ManageClinic::class);

        return [
            Action::make('plan')->label('Change plan')
                ->fillForm(fn (): array => ['plan_id' => $this->record->plan_id])
                ->modalDescription('Modules outside the new plan are switched off; their data is kept and returns when the plan includes them again.')
                ->schema([Select::make('plan_id')->label('Plan')->required()->native(false)
                    ->options(fn (): array => Plan::orderBy('sort_order')->get()->mapWithKeys(fn (Plan $p): array => [$p->id => "{$p->name} · ৳ {$p->price_monthly->formatted()} a month · {$p->branchLimitLabel()}"])->all())])
                ->action(function (Action $action, array $data) use ($manage): void {
                    $this->run($action, function () use ($manage, $data): void {
                        $result = $manage()->changePlan($this->record, Plan::findOrFail($data['plan_id']));
                        if ($result['switched_off'] !== []) {
                            Notification::make()->warning()->title('Switched off (data kept): '.implode(', ', $result['switched_off']))->send();
                        }
                    }, 'Plan changed');
                }),

            Action::make('invoice')->label('Issue invoice')
                ->fillForm(fn (): array => ['billing_period' => 'monthly'])
                ->modalDescription(fn (): string => 'For the next period, starting '.app(Billing::class)->nextPeriod($this->record, 'monthly')[0]->format('d M Y').'. Leave the amount empty to use the plan\'s price.')
                ->schema([
                    Select::make('billing_period')->label('Period')->options(['monthly' => 'One month', 'yearly' => 'One year'])->required()->native(false),
                    TextInput::make('amount')->label('Amount (empty: the plan\'s price)')->prefix('৳')->rule('nullable')->rule('regex:/^\d{1,10}(\.\d{1,2})?$/'),
                    TextInput::make('note')->label('Note on the invoice')->maxLength(255)->placeholder('Launch discount'),
                ])
                ->action(fn (Action $action, array $data) => $this->run($action, fn () => app(Billing::class)->issue($this->record, (string) $data['billing_period'], $data['amount'] ?? null, $data['note'] ?? null), 'Invoice issued')),

            Action::make('invoices')->label('Invoices')->color('gray')
                ->url(fn (): string => InvoiceResource::getUrl('index', ['filters' => ['clinic_id' => ['value' => $this->record->id]]])),

            Action::make('extend')->label('Extend trial')->color('gray')
                ->visible(fn (): bool => $this->record->paid_until === null)
                ->schema([TextInput::make('days')->label('Add days')->numeric()->minValue(1)->maxValue(365)->default(14)->required()])
                ->action(fn (Action $action, array $data) => $this->run($action, fn () => $manage()->extendTrial($this->record, (int) $data['days']), 'Trial extended')),

            Action::make('dates')->label('Set dates')->color('gray')
                ->fillForm(fn (): array => [
                    'trial_ends' => $this->record->trial_ends_at?->timezone($this->record->timezone ?: 'Asia/Dhaka')->toDateString(),
                    'paid_until' => $this->record->paid_until?->toDateString(),
                ])
                ->modalDescription('Paid-until moves the clinic to active at once when it is in the future. Payments are recorded with invoices in the next step (8d).')
                ->schema([
                    DatePicker::make('trial_ends')->label('Trial ends on')->native(false),
                    DatePicker::make('paid_until')->label('Paid until')->native(false),
                ])
                ->action(fn (Action $action, array $data) => $this->run($action, fn () => $manage()->setDates($this->record, $data['trial_ends'] ?? null, $data['paid_until'] ?? null), 'Dates saved')),

            ActionGroup::make([
                Action::make('suspend')->label('Suspend')->color('danger')
                    ->visible(fn (): bool => $this->record->status !== ClinicStatus::Suspended)
                    ->modalDescription('The clinic\'s address stops working at once, for staff and for patients. Use read-only for unpaid clinics; suspend only for a real reason.')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (Action $action, array $data) => $this->run($action, fn () => $manage()->suspend($this->record, (string) $data['reason']), 'Clinic suspended')),
                Action::make('reactivate')->label('Reactivate')
                    ->visible(fn (): bool => in_array($this->record->status, [ClinicStatus::Suspended, ClinicStatus::Closed], true))
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (Action $action, array $data) => $this->run($action, fn () => $manage()->reactivate($this->record, (string) $data['reason']), 'Clinic reactivated')),
            ])->label('More')->button()->color('gray'),
        ];
    }
}
