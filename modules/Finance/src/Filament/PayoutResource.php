<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Money\Money;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Finance\Models\CommissionPayout;
use Modules\Finance\Permissions;
use UnitEnum;

class PayoutResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = CommissionPayout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Commission payouts';

    protected static ?string $modelLabel = 'payout';

    protected static ?string $slug = 'finance/payouts';

    public static function moduleKey(): string
    {
        return 'finance';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::COMMISSION_VIEW : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['doctor', 'account', 'creator']))
            ->columns([
                TextColumn::make('payout_no')->label('No.'),
                TextColumn::make('paid_at')->label('Date')->dateTime('d M Y, h:i A', app(ClinicContext::class)->timezone()),
                TextColumn::make('doctor.name')->label('Doctor')->searchable(),
                TextColumn::make('account.name')->label('Paid from'),
                TextColumn::make('note')->placeholder('-'),
                TextColumn::make('creator.name')->label('Paid by'),
                TextColumn::make('amount')->alignEnd()->weight('bold')->formatStateUsing(fn (Money $state): string => '৳ '.$state->formatted()),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPayouts::route('/')];
    }
}
