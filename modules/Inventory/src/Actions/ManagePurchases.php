<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use App\Core\Modules\ModuleState;
use App\Core\Money\Money;
use App\Core\Numbering\NumberGenerator;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Finance\Actions\ManageExpenses;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ExpenseCategory;
use Modules\Finance\Support\FinanceException;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Models\PurchaseLine;
use Modules\Inventory\Models\Supplier;
use Modules\Inventory\Permissions;
use Modules\Inventory\Support\InventoryException;
use Modules\Inventory\Support\Quantity;
use Modules\Inventory\Support\StockLedger;

/**
 * Stock coming in from suppliers: record, void, and pay. A purchase is never
 * edited; a wrong one is voided, which takes its stock out again.
 */
final class ManagePurchases
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StockLedger $ledger,
        private readonly AuditLogger $audit,
        private readonly ClinicContext $clinics,
    ) {}

    /**
     * @param  list<array{item_id: int|string, quantity: string, unit_cost: string, batch_no?: ?string, expires_on?: ?string}>  $lines
     */
    public function record(Branch $branch, Supplier $supplier, string $date, array $lines, ?string $supplierBillNo = null, ?string $note = null): Purchase
    {
        Gate::authorize(Permissions::PURCHASES_RECORD, $branch);

        if (! $supplier->is_active) {
            throw new InventoryException('This supplier is not active.');
        }

        $timezone = $this->clinics->require()->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $day = $this->day($date, $timezone);

        if ($day->isAfter($today)) {
            throw new InventoryException('The purchase date cannot be in the future.');
        }

        $prepared = $this->prepare($lines, $today);

        return DB::connection('clinic')->transaction(function () use ($branch, $supplier, $day, $prepared, $supplierBillNo, $note): Purchase {
            $purchase = Purchase::create([
                'purchase_no' => $this->numbers->generate('purchase', $branch),
                'supplier_id' => $supplier->id,
                'branch_id' => $branch->id,
                'purchase_date' => $day->toDateString(),
                'supplier_bill_no' => filled($supplierBillNo) ? trim((string) $supplierBillNo) : null,
                'note' => filled($note) ? trim((string) $note) : null,
                'created_by' => Auth::id(),
            ]);

            $total = Money::zero();

            foreach ($prepared as $line) {
                $row = PurchaseLine::create([
                    'purchase_id' => $purchase->id,
                    'item_id' => $line['item']->id,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'line_total' => $line['total'],
                    'batch_no' => $line['batch_no'],
                    'expires_on' => $line['expires_on'],
                ]);

                $this->ledger->post(
                    $line['item'], $line['quantity'], $line['total'], 'purchase', 'purchase_line', $row->id, $branch->id,
                    $line['batch_no'], $line['expires_on'], $purchase->purchase_no, $day->addHours(12),
                );

                $total = $total->plus($line['total']);
            }

            $purchase->forceFill(['total' => $total])->save();

            $this->audit->record('purchase.recorded', $purchase, [], [
                'purchase_no' => $purchase->purchase_no,
                'supplier' => $supplier->name,
                'date' => $day->toDateString(),
                'total' => $total->toDecimal(),
                'lines' => array_map(fn (array $l): string => "{$l['item']->name}: {$l['quantity']->formatted()} × {$l['unit_cost']->toDecimal()}", $prepared),
            ]);

            return $purchase;
        });
    }

    /** Take the purchase's stock out again. Only an unpaid purchase whose stock is still on hand can be voided. */
    public function void(Purchase $purchase, string $reason): Purchase
    {
        Gate::authorize(Permissions::PURCHASES_VOID, $purchase->branch_id);

        if (blank($reason)) {
            throw new InventoryException('A reason is required to void a purchase.');
        }

        return DB::connection('clinic')->transaction(function () use ($purchase, $reason): Purchase {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($purchase->isVoid()) {
                throw new InventoryException('This purchase is already void.');
            }

            if ($purchase->isPaid()) {
                throw new InventoryException('A paid purchase cannot be voided: its payment is already in the accounts. Record a correcting stock entry instead.');
            }

            foreach ($purchase->lines()->with('item')->get() as $line) {
                $this->ledger->post(
                    $line->item, $line->quantity->negate(), $line->line_total->negate(), 'purchase_void', 'purchase_line_void', $line->id,
                    $purchase->branch_id, $line->batch_no, $line->expires_on?->toDateString(), "Void of {$purchase->purchase_no}",
                );
            }

            $purchase->forceFill(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => trim($reason)])->save();

            $this->audit->record('purchase.voided', $purchase, [], ['purchase_no' => $purchase->purchase_no, 'total' => $purchase->total->toDecimal()], ['reason' => trim($reason)]);

            return $purchase;
        });
    }

    /**
     * Mark a purchase paid. With the finance module on, the money is recorded
     * as an expense ("Purchases") from the account chosen; without it, only
     * the date is kept.
     */
    public function pay(Purchase $purchase, string $date, ?int $accountId = null, ?string $note = null): Purchase
    {
        Gate::authorize(Permissions::PURCHASES_PAY, $purchase->branch_id);

        $financeOn = app(ModuleState::class)->isEnabled('finance');
        $day = $this->day($date, $this->clinics->require()->timezone);

        return DB::connection('clinic')->transaction(function () use ($purchase, $day, $accountId, $note, $financeOn): Purchase {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->with('supplier')->firstOrFail();

            if ($purchase->isVoid()) {
                throw new InventoryException('A void purchase cannot be paid.');
            }

            if ($purchase->isPaid()) {
                throw new InventoryException('This purchase is already paid.');
            }

            $expenseId = null;

            if ($financeOn && $purchase->total->isPositive()) {
                $account = $accountId ? Account::where('is_active', true)->find($accountId) : null;

                if ($account === null) {
                    throw new InventoryException('Choose the account the money was paid from.');
                }

                $category = ExpenseCategory::firstOrCreate(['name' => 'Purchases']);

                try {
                    $expense = app(ManageExpenses::class)->record(
                        Branch::findOrFail($purchase->branch_id),
                        $category,
                        $account,
                        $purchase->total,
                        $day->toDateString(),
                        "Purchase {$purchase->purchase_no}".($purchase->supplier_bill_no ? " (bill {$purchase->supplier_bill_no})" : ''),
                        $purchase->supplier?->name,
                    );
                } catch (FinanceException $e) {
                    throw new InventoryException($e->getMessage(), previous: $e);
                }

                $expenseId = $expense->id;
            }

            $purchase->forceFill([
                'paid_at' => $day->addHours(12),
                'paid_by' => Auth::id(),
                'expense_id' => $expenseId,
                'payment_note' => filled($note) ? mb_substr(trim((string) $note), 0, 255) : null,
            ])->save();

            $this->audit->record('purchase.paid', $purchase, [], [
                'purchase_no' => $purchase->purchase_no,
                'total' => $purchase->total->toDecimal(),
                'date' => $day->toDateString(),
                'expense_id' => $expenseId,
            ]);

            return $purchase;
        });
    }

    private function day(string $date, string $timezone): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($date, $timezone)->startOfDay();
        } catch (\Throwable) {
            throw new InventoryException('The date is not valid.');
        }
    }

    /**
     * Check every line before anything is written.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{item: Item, quantity: Quantity, unit_cost: Money, total: Money, batch_no: ?string, expires_on: ?string}>
     */
    private function prepare(array $lines, CarbonImmutable $today): array
    {
        if ($lines === []) {
            throw new InventoryException('Add at least one item to the purchase.');
        }

        $items = Item::whereIn('id', array_map(fn (array $l): int => (int) ($l['item_id'] ?? 0), $lines))->get()->keyBy('id');
        $prepared = [];

        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $item = $items->get((int) ($line['item_id'] ?? 0));

            if ($item === null || ! $item->is_active) {
                throw new InventoryException("Line {$n}: choose an active stock item.");
            }

            try {
                $quantity = Quantity::fromDecimal((string) ($line['quantity'] ?? ''));
                $unitCost = Money::fromDecimal((string) ($line['unit_cost'] ?? ''));
            } catch (InvalidArgumentException) {
                throw new InventoryException("Line {$n} ({$item->name}): enter a quantity and a unit cost as numbers.");
            }

            if (! $quantity->isPositive()) {
                throw new InventoryException("Line {$n} ({$item->name}): the quantity must be more than zero.");
            }

            if ($unitCost->isNegative()) {
                throw new InventoryException("Line {$n} ({$item->name}): the unit cost cannot be negative.");
            }

            $batch = filled($line['batch_no'] ?? null) ? mb_substr(trim((string) $line['batch_no']), 0, 60) : null;
            $expires = filled($line['expires_on'] ?? null) ? $this->day((string) $line['expires_on'], $today->timezone->getName()) : null;

            if ($item->tracks_expiry && $expires === null) {
                throw new InventoryException("Line {$n} ({$item->name}): this item tracks expiry, so enter its expiry date.");
            }

            if ($expires !== null && $expires->isBefore($today)) {
                throw new InventoryException("Line {$n} ({$item->name}): the expiry date has already passed.");
            }

            // A tracked item without a batch number is kept under its expiry date, so stock can still be taken by batch.
            if ($item->tracks_expiry && $batch === null) {
                $batch = 'EXP-'.$expires->format('Ymd');
            }

            $prepared[] = [
                'item' => $item,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total' => $quantity->costAt($unitCost),
                'batch_no' => $batch,
                'expires_on' => $expires?->toDateString(),
            ];
        }

        return $prepared;
    }
}
