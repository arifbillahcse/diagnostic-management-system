<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Refunds;

use App\Core\Money\Money;
use App\Core\Tenancy\ClinicContext;
use App\Filament\Support\BranchFilter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Actions\DecideRefund;
use Modules\FrontDesk\Enums\RefundStatus;
use Modules\FrontDesk\Filament\Concerns\FrontDeskScreen;
use Modules\FrontDesk\Filament\Orders\OrderResource;
use Modules\FrontDesk\Models\Refund;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\FrontDeskException;
use UnitEnum;

class RefundResource extends Resource
{
    use FrontDeskScreen;

    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Front desk';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'refunds';

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::ORDERS_VIEW : null;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess() || ! Gate::allows(Permissions::REFUNDS_APPROVE)) {
            return null;
        }

        $waiting = Refund::where('status', RefundStatus::Pending->value)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    /** Run a decision and report a refusal as a notification. */
    private static function decide(callable $decision, string $success): void
    {
        try {
            $decision();
            Notification::make()->success()->title($success)->send();
        } catch (FrontDeskException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('Not done')->body('You do not have permission to do this.')->send();
        }
    }

    public static function table(Table $table): Table
    {
        $pendingAndAllowed = fn (Refund $record): bool => $record->status === RefundStatus::Pending
            && Gate::allows(Permissions::REFUNDS_APPROVE, $record->branch_id);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['invoice.patient', 'invoice.order', 'requester']))
            ->columns([
                TextColumn::make('requested_at')->label('Requested')->dateTime('d M Y, h:i A', app(ClinicContext::class)->timezone()),
                TextColumn::make('invoice.invoice_no')->label('Invoice')->description(fn (Refund $record): ?string => $record->invoice?->patient?->name),
                TextColumn::make('amount')->alignEnd()->formatStateUsing(fn (Money $state): string => '৳ '.$state->formatted())
                    ->description(fn (Refund $record): string => $record->method->label()),
                TextColumn::make('reason')->wrap(),
                TextColumn::make('requester.name')->label('Requested by'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (RefundStatus $state): string => $state->label())
                    ->color(fn (RefundStatus $state): string => match ($state) {
                        RefundStatus::Pending => 'warning',
                        RefundStatus::Approved => 'success',
                        RefundStatus::Rejected => 'gray',
                    }),
            ])
            ->modifyQueryUsing(BranchFilter::scope())
            ->filters([
                BranchFilter::make(),
                SelectFilter::make('status')->options(RefundStatus::options())->default(RefundStatus::Pending->value),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->visible($pendingAndAllowed)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Refund $record): string => "Give back ৳ {$record->amount->formatted()} by {$record->method->label()}? A cash refund comes out of your open cash session.")
                    ->action(fn (Refund $record) => static::decide(fn () => app(DecideRefund::class)->approve($record), 'Refund approved')),
                Action::make('reject')
                    ->color('danger')
                    ->visible($pendingAndAllowed)
                    ->schema([Textarea::make('note')->label('Reason')->required()->rows(2)->maxLength(255)])
                    ->action(fn (Refund $record, array $data) => static::decide(fn () => app(DecideRefund::class)->reject($record, $data['note']), 'Refund rejected')),
                Action::make('open')->label('Open order')->color('gray')
                    ->url(fn (Refund $record): string => OrderResource::getUrl('view', ['record' => $record->invoice->order])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRefunds::route('/')];
    }
}
