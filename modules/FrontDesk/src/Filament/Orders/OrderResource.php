<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Orders;

use App\Core\Money\Money;
use App\Core\Patients\Patient;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Filament\Concerns\FrontDeskScreen;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Permissions;
use UnitEnum;

class OrderResource extends Resource
{
    use FrontDeskScreen;

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Front desk';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'order_no';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static function permissionFor(string $operation): ?string
    {
        return match ($operation) {
            'viewAny', 'view' => Permissions::ORDERS_VIEW,
            'create' => Permissions::ORDERS_CREATE,
            default => null, // changes go through the actions on the order screen
        };
    }

    public static function invoiceStatusColor(?InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Paid => 'success',
            InvoiceStatus::PartiallyPaid => 'warning',
            InvoiceStatus::Issued => 'danger',
            InvoiceStatus::Refunded => 'info',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['patient', 'invoice']))
            ->columns([
                TextColumn::make('order_no')->label('Order')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search): void {
                        // Index-friendly: order numbers from their start, or the patient search.
                        $q->where('order_no', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $search).'%')
                            ->orWhereIn('patient_id', Patient::search($search)->select('id'));
                    })),
                TextColumn::make('ordered_at')->label('Date')->dateTime('d M Y, h:i A', app(ClinicContext::class)->timezone()),
                TextColumn::make('patient.name')->label('Patient')->description(fn (Order $record): ?string => $record->patient?->patient_code),
                TextColumn::make('invoice.total')->label('Total')->alignEnd()->formatStateUsing(fn (?Money $state): string => '৳ '.($state?->formatted() ?? '0.00')),
                TextColumn::make('invoice.due_amount')->label('Due')->alignEnd()->formatStateUsing(fn (?Money $state): string => '৳ '.($state?->formatted() ?? '0.00')),
                TextColumn::make('invoice.status')->label('Payment')->badge()
                    ->formatStateUsing(fn (?InvoiceStatus $state): string => $state?->label() ?? '-')
                    ->color(fn (?InvoiceStatus $state): string => static::invoiceStatusColor($state)),
                TextColumn::make('status')->badge()->color('gray')->formatStateUsing(fn (OrderStatus $state): string => $state->label()),
            ])
            ->searchPlaceholder('Order number or patient')
            ->filters([
                SelectFilter::make('status')->options(OrderStatus::options()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => OpenBillingScreen::route('/create'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
