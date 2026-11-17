<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Cash;

use App\Core\Money\Money;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Enums\CashSessionStatus;
use Modules\FrontDesk\Filament\Concerns\FrontDeskScreen;
use Modules\FrontDesk\Models\CashSession;
use Modules\FrontDesk\Permissions;
use UnitEnum;

class CashSessionResource extends Resource
{
    use FrontDeskScreen;

    protected static ?string $model = CashSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Front desk';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Cash sessions';

    protected static ?string $slug = 'cash-sessions';

    protected static function permissionFor(string $operation): ?string
    {
        if (! in_array($operation, ['viewAny', 'view'], true)) {
            return null;
        }

        return Gate::allows(Permissions::CASH_VIEW_ALL) ? Permissions::CASH_VIEW_ALL : Permissions::CASH_MANAGE;
    }

    public static function getEloquentQuery(): Builder
    {
        // Staff see their own sessions; a supervisor sees everyone's.
        return parent::getEloquentQuery()
            ->with('opener')
            ->when(! Gate::allows(Permissions::CASH_VIEW_ALL), fn (Builder $query) => $query->where('opened_by', Auth::id()));
    }

    public static function table(Table $table): Table
    {
        $money = fn (?Money $state): string => $state === null ? '-' : '৳ '.$state->formatted();

        return $table
            ->columns([
                TextColumn::make('opened_at')->label('Opened')->dateTime('d M Y, h:i A', app(ClinicContext::class)->timezone()),
                TextColumn::make('opener.name')->label('Cashier'),
                TextColumn::make('opening_float')->label('Opening')->alignEnd()->formatStateUsing($money),
                TextColumn::make('expected_cash')->label('Expected')->alignEnd()->formatStateUsing($money)->placeholder('-'),
                TextColumn::make('counted_cash')->label('Counted')->alignEnd()->formatStateUsing($money)->placeholder('-'),
                TextColumn::make('difference')->alignEnd()->formatStateUsing($money)->placeholder('-')
                    ->color(fn (?Money $state): ?string => $state !== null && ! $state->isZero() ? 'danger' : null)
                    ->description(fn (CashSession $record): ?string => $record->notes),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (CashSessionStatus $state): string => $state->label())
                    ->color(fn (CashSessionStatus $state): string => $state === CashSessionStatus::Open ? 'success' : 'gray'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCashSessions::route('/')];
    }
}
