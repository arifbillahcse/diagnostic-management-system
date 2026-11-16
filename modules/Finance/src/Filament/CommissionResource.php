<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Money\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Actions\ManageCommission;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\CommissionRule;
use Modules\Finance\Permissions;
use Modules\Finance\Support\Commission;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Service;
use UnitEnum;

/** Each referral doctor's commission: rates, earned, paid, and owed. */
class CommissionResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = ReferralDoctor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Referral commission';

    protected static ?string $modelLabel = 'doctor';

    protected static ?string $slug = 'finance/commission';

    public static function moduleKey(): string
    {
        return 'finance';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::COMMISSION_VIEW : null;
    }

    private static function kind(): Select
    {
        return Select::make('kind')->label('Kind')->options([CommissionRule::PERCENT => 'Percent of what the patient paid', CommissionRule::FIXED => 'Fixed amount per test'])
            ->native(false)->default(CommissionRule::PERCENT);
    }

    private static function value(): TextInput
    {
        return TextInput::make('value')->label('Rate')->rule('regex:/^\d{1,10}(\.\d{1,2})?$/')
            ->validationMessages(['regex' => 'Enter a number such as 10 or 12.50.']);
    }

    private static function describeDefault(ReferralDoctor $doctor): string
    {
        $rule = CommissionRule::where('referral_doctor_id', $doctor->id)->whereNull('service_id')->first();
        $extra = CommissionRule::where('referral_doctor_id', $doctor->id)->whereNotNull('service_id')->count();

        $text = $rule === null ? 'Not set' : ($rule->kind === CommissionRule::PERCENT ? rtrim(rtrim($rule->valueText(), '0'), '.').'%' : '৳ '.$rule->valueText().' per test');

        return $text.($extra > 0 ? " (+{$extra} special)" : '');
    }

    public static function table(Table $table): Table
    {
        $commission = app(Commission::class);
        $money = fn (Money $amount): string => '৳ '.$amount->formatted();

        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold')->description(fn (ReferralDoctor $record): ?string => $record->degree),
                TextColumn::make('rate')->state(fn (ReferralDoctor $record): string => static::describeDefault($record)),
                TextColumn::make('earned')->alignEnd()->state(fn (ReferralDoctor $record): string => $money($commission->earned($record))),
                TextColumn::make('paid')->alignEnd()->state(fn (ReferralDoctor $record): string => $money($commission->paid($record))),
                TextColumn::make('owed')->alignEnd()->weight('bold')->state(fn (ReferralDoctor $record): string => $money($commission->balance($record))),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('rates')
                    ->label('Set rates')
                    ->visible(fn (): bool => Gate::allows(Permissions::COMMISSION_MANAGE))
                    ->modalDescription('New rates apply to commission earned from now on. Commission already earned is not recalculated.')
                    ->fillForm(function (ReferralDoctor $record): array {
                        $rules = CommissionRule::where('referral_doctor_id', $record->id)->get();
                        $default = $rules->firstWhere('service_id', null);

                        return [
                            'kind' => $default?->kind ?? CommissionRule::PERCENT,
                            'value' => $default?->valueText(),
                            'overrides' => $rules->whereNotNull('service_id')->map(fn (CommissionRule $r): array => ['service_id' => $r->service_id, 'kind' => $r->kind, 'value' => $r->valueText()])->values()->all(),
                        ];
                    })
                    ->schema([
                        Section::make('For every test')->columns(2)->schema([static::kind(), static::value()->helperText('Leave empty for no commission.')]),
                        Repeater::make('overrides')->label('Special rates for particular tests')->defaultItems(0)->columns(3)->addActionLabel('Add a special rate')
                            ->schema([
                                Select::make('service_id')->label('Test')->required()->searchable()->distinct()
                                    ->options(fn (): array => Service::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                                static::kind()->required(),
                                static::value()->required(),
                            ]),
                    ])
                    ->action(fn (Action $action, ReferralDoctor $record, array $data) => Guard::run($action, fn () => app(ManageCommission::class)->setRates(
                        $record,
                        filled($data['value'] ?? null) ? ['kind' => $data['kind'], 'value' => $data['value']] : null,
                        $data['overrides'] ?? [],
                    ), 'Rates saved')),

                Action::make('pay')
                    ->label('Pay')
                    ->color('success')
                    ->visible(fn (ReferralDoctor $record): bool => Gate::allows(Permissions::COMMISSION_PAY) && $commission->balance($record)->isPositive())
                    ->fillForm(fn (ReferralDoctor $record): array => ['amount' => $commission->balance($record)->toDecimal()])
                    ->schema([
                        Guard::amount('amount', 'Amount to pay'),
                        Select::make('account_id')->label('Paid from')->required()->native(false)
                            ->options(fn (): array => Account::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                        TextInput::make('note')->maxLength(200)->placeholder('For example: November'),
                    ])
                    ->action(fn (Action $action, ReferralDoctor $record, array $data) => Guard::run($action, fn () => app(ManageCommission::class)->pay(
                        $record, Account::findOrFail($data['account_id']), Money::fromDecimal($data['amount']), $data['note'] ?? null,
                    ), 'Commission paid')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCommission::route('/')];
    }
}
