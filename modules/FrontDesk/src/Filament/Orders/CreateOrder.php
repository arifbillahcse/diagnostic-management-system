<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Orders;

use App\Core\Branches\CurrentBranch;
use App\Core\Money\Money;
use App\Core\Patients\Patient;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Actions\RecordPayment;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Support\FrontDeskException;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'New order';

    protected static bool $canCreateAnother = false;

    /** Made once per form, so a double click cannot take the payment twice. */
    public string $paymentKey = '';

    public function mount(): void
    {
        parent::mount();
        $this->paymentKey = (string) Str::uuid();
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $order = app(PlaceOrder::class)->handle(
                Patient::findOrFail($data['patient_id']),
                app(CurrentBranch::class)->get(),
                array_map('intval', $data['service_ids'] ?? []),
                $data,
            );
        } catch (FrontDeskException $e) {
            Notification::make()->danger()->title('Order not created')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }

        if (filled($data['pay_amount'] ?? null) && Money::fromDecimal((string) $data['pay_amount'])->isPositive()) {
            try {
                app(RecordPayment::class)->handle(
                    $order->invoice,
                    Money::fromDecimal((string) $data['pay_amount']),
                    PaymentMethod::from($data['pay_method'] ?? PaymentMethod::Cash->value),
                    $data['pay_reference'] ?? null,
                    $this->paymentKey,
                );
            } catch (FrontDeskException $e) {
                // The order stands; only the payment was refused. Say so
                // clearly and let staff take the payment on the order screen.
                Notification::make()->warning()->title('Order created, but the payment was not recorded')
                    ->body($e->getMessage())->persistent()->send();
            }
        }

        return $order;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Order created';
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
