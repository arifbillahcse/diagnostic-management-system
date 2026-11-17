<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Orders;

use App\Core\Money\Money;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Filament\Concerns\FrontDeskScreen;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Permissions;
use UnitEnum;

/** Unpaid and partly paid invoices, oldest first: the follow-up list. */
class DueResource extends Resource
{
    use FrontDeskScreen;

    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Front desk';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Dues';

    protected static ?string $modelLabel = 'due';

    protected static ?string $slug = 'dues';

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::ORDERS_VIEW : null;
    }

    public static function getEloquentQuery(): Builder
    {
        // Uses the (branch, status, issued date) index.
        return parent::getEloquentQuery()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->with(['patient', 'order']);
    }

    public static function table(Table $table): Table
    {
        $money = fn (Money $state): string => '৳ '.$state->formatted();

        return $table
            ->columns([
                TextColumn::make('invoice_no')->label('Invoice'),
                TextColumn::make('issued_at')->label('Date')->date('d M Y', app(ClinicContext::class)->timezone()),
                TextColumn::make('patient.name')->label('Patient')->description(fn (Invoice $record): ?string => $record->patient?->phone),
                TextColumn::make('total')->alignEnd()->formatStateUsing($money),
                TextColumn::make('paid_total')->label('Paid')->alignEnd()->formatStateUsing($money),
                TextColumn::make('due_amount')->label('Due')->alignEnd()->weight('bold')->formatStateUsing($money),
            ])
            ->defaultSort('issued_at')
            ->recordActions([
                Action::make('open')->label('Open order')
                    ->url(fn (Invoice $record): string => OrderResource::getUrl('view', ['record' => $record->order])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDues::route('/')];
    }
}
