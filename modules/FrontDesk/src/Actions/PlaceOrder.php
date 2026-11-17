<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Money\Money;
use App\Core\Numbering\NumberGenerator;
use App\Core\Patients\Patient;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Enums\LineKind;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Events\InvoiceIssued;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\InvoiceLine;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\OrderItem;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\Addons;
use Modules\FrontDesk\Support\DeliveryTime;
use Modules\FrontDesk\Support\DiscountRules;
use Modules\FrontDesk\Support\FrontDeskException;
use Modules\FrontDesk\Support\InvoiceCalculator;

/**
 * Creates an order and its invoice in one step.
 *
 * Options: referral_doctor_id, notes, discount_type ("percent" or
 * "amount"), discount_value, discount_reason, delivery_at (when the report
 * is promised; the clinic's usual time when omitted).
 *
 * Supplies set up as add-ons of a chosen service (a tube, a needle) are
 * added to the order and the invoice, once each.
 */
final class PlaceOrder
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly DiscountRules $discounts,
        private readonly InvoiceCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly DeliveryTime $delivery,
        private readonly Addons $addons,
    ) {}

    /**
     * @param  list<int>  $serviceIds
     * @param  array<string, mixed>  $options
     */
    public function handle(Patient $patient, Branch $branch, array $serviceIds, array $options = []): Order
    {
        Gate::authorize(Permissions::ORDERS_CREATE, $branch);

        /** @var User $user */
        $user = Auth::user();

        if (! $branch->is_active) {
            throw new FrontDeskException('This branch is not active.');
        }

        $services = $this->services($serviceIds);
        $services = [...$services, ...$this->addons->for($services)];
        $subtotal = Money::sum(...array_map(fn (Service $s): Money => $s->price, $services));

        $discount = $this->discounts->amount($subtotal, $options['discount_type'] ?? null, $options['discount_value'] ?? null);
        $this->discounts->authorize($user, $branch, $subtotal, $discount, $options['discount_reason'] ?? null);

        // Share the discount across the items in proportion to their price,
        // so cancelling one item later takes back exactly its share.
        $shares = $discount->isZero()
            ? array_fill(0, count($services), Money::zero())
            : $discount->allocate(array_map(fn (Service $s): int => $s->price->minor, $services));

        $doctorId = $this->doctorId($options['referral_doctor_id'] ?? null);

        $order = DB::connection('clinic')->transaction(function () use ($patient, $branch, $services, $shares, $subtotal, $discount, $doctorId, $options, $user): Order {
            $now = now();

            $order = Order::create([
                'order_no' => $this->numbers->generate('order', $branch),
                'patient_id' => $patient->id,
                'referral_doctor_id' => $doctorId,
                'branch_id' => $branch->id,
                'status' => OrderStatus::Confirmed,
                'ordered_at' => $now,
                'delivery_at' => $this->deliveryAt($options['delivery_at'] ?? null, $now),
                'notes' => filled($options['notes'] ?? null) ? trim((string) $options['notes']) : null,
                'created_by' => $user->id,
            ]);

            $invoice = Invoice::create([
                'invoice_no' => $this->numbers->generate('invoice', $branch),
                'order_id' => $order->id,
                'patient_id' => $patient->id,
                'branch_id' => $branch->id,
                'status' => InvoiceStatus::Issued,
                'discount_reason' => $discount->isZero() ? null : trim((string) $options['discount_reason']),
                'discount_by' => $discount->isZero() ? null : $user->id,
                'issued_at' => $now,
            ]);

            foreach ($services as $i => $service) {
                $item = $this->addItem($order, $service, $service->price, $shares[$i], null);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'order_item_id' => $item->id,
                    'kind' => LineKind::Charge,
                    'description' => $service->name,
                    'amount' => $service->price,
                    'created_by' => $user->id,
                ]);

                foreach ($service->isPackage() ? $service->packageItems : [] as $component) {
                    $this->addItem($order, $component, Money::zero(), Money::zero(), $item->id);
                }
            }

            if (! $discount->isZero()) {
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'kind' => LineKind::Discount,
                    'description' => 'Discount: '.$invoice->discount_reason,
                    'amount' => $discount->negate(),
                    'created_by' => $user->id,
                ]);
            }

            $this->calculator->refresh($invoice);

            $this->audit->record('order.placed', $order, [], [
                'order_no' => $order->order_no,
                'invoice_no' => $invoice->invoice_no,
                'patient' => $patient->patient_code,
                'services' => array_map(fn (Service $s): string => $s->name, $services),
                'subtotal' => $subtotal->toDecimal(),
                'discount' => $discount->toDecimal(),
                'total' => $invoice->total->toDecimal(),
            ], $discount->isZero() ? [] : ['discount_reason' => $invoice->discount_reason]);

            return $order->setRelation('invoice', $invoice);
        });

        event(new OrderPlaced($order));
        event(new InvoiceIssued($order->invoice));

        return $order;
    }

    private function addItem(Order $order, Service $service, Money $price, Money $discount, ?int $packageItemOf): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'service_type' => $service->type,
            'unit_price' => $price,
            'discount_amount' => $discount,
            'net_amount' => $price->minus($discount),
            'package_item_of' => $packageItemOf,
            'status' => ItemStatus::Active,
        ]);
    }

    /**
     * Load the chosen services, in the order chosen, and refuse anything
     * that cannot be ordered.
     *
     * @param  list<int>  $serviceIds
     * @return list<Service>
     */
    private function services(array $serviceIds): array
    {
        $ids = array_values(array_map('intval', $serviceIds));

        if ($ids === []) {
            throw new FrontDeskException('Choose at least one test or service.');
        }

        if (count($ids) !== count(array_unique($ids))) {
            throw new FrontDeskException('The same service was chosen twice.');
        }

        $found = Service::with('packageItems')->whereIn('id', $ids)->where('is_active', true)->get()->keyBy('id');
        $services = [];
        $coveredBy = [];

        foreach ($ids as $id) {
            $service = $found[$id] ?? throw new FrontDeskException('One of the chosen services is not available any more.');

            if ($service->isPackage()) {
                if ($service->packageItems->isEmpty()) {
                    throw new FrontDeskException("The package \"{$service->name}\" has no tests in it.");
                }

                foreach ($service->packageItems as $component) {
                    if (isset($coveredBy[$component->id])) {
                        throw new FrontDeskException("\"{$component->name}\" is in both \"{$coveredBy[$component->id]}\" and \"{$service->name}\". Choose one of the packages.");
                    }

                    $coveredBy[$component->id] = $service->name;
                }
            }

            $services[] = $service;
        }

        foreach ($services as $service) {
            if (isset($coveredBy[$service->id])) {
                throw new FrontDeskException("\"{$service->name}\" is already part of the package \"{$coveredBy[$service->id]}\".");
            }
        }

        return $services;
    }

    private function deliveryAt(mixed $chosen, CarbonInterface $orderedAt): CarbonInterface
    {
        if (blank($chosen)) {
            return $this->delivery->after($orderedAt);
        }

        try {
            $at = CarbonImmutable::parse((string) $chosen, app(ClinicContext::class)->require()->timezone)->utc();
        } catch (\Throwable) {
            throw new FrontDeskException('The delivery time is not valid.');
        }

        if ($at->lessThan($orderedAt->copy()->subMinute())) {
            throw new FrontDeskException('The delivery time cannot be before the order.');
        }

        return $at;
    }

    private function doctorId(mixed $id): ?int
    {
        if (blank($id)) {
            return null;
        }

        return ReferralDoctor::where('is_active', true)->whereKey((int) $id)->value('id')
            ?? throw new FrontDeskException('The chosen referral doctor is not available.');
    }
}
