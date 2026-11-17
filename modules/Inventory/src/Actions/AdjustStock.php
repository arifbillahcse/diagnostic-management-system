<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Inventory\Enums;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Shortfall;
use Modules\Inventory\Permissions;
use Modules\Inventory\Support\InventoryException;
use Modules\Inventory\Support\Quantity;
use Modules\Inventory\Support\StockLedger;

/**
 * Stock changes made by hand: taking stock out (issued, wasted, expired), a
 * stock count, and sorting out a shortfall. Each is a new movement, never an
 * edit, and is audited.
 */
final class AdjustStock
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function takeOut(Item $item, string $reason, string $quantity, ?string $batchNo = null, ?string $note = null): void
    {
        Gate::authorize(Permissions::STOCK_ADJUST);

        if (! array_key_exists($reason, Enums::MANUAL_OUT)) {
            throw new InventoryException('Choose why the stock is going out.');
        }

        if ($reason !== 'issue' && blank($note)) {
            throw new InventoryException('Say what happened, for example "bottle broken".');
        }

        $wanted = $this->quantity($quantity);

        if ($item->tracks_expiry && blank($batchNo)) {
            throw new InventoryException("{$item->name} is kept by batch: choose the batch.");
        }

        DB::connection('clinic')->transaction(function () use ($item, $reason, $wanted, $batchNo, $note): void {
            $available = $item->tracks_expiry ? $this->ledger->batchQuantity($item, (string) $batchNo) : $item->fresh()->quantity;

            if ($wanted->greaterThan($available)) {
                throw new InventoryException("Only {$available->formatted()} {$item->unit} of {$item->name}".($batchNo ? " in batch {$batchNo}" : '').' is in stock.');
            }

            $this->ledger->post($item, $wanted->negate(), null, $reason, batchNo: $item->tracks_expiry ? $batchNo : null,
                expiresOn: $item->tracks_expiry ? $this->expiryOf($item, (string) $batchNo) : null, note: $note);

            $this->audit->record('stock.taken_out', $item, [], [
                'item' => $item->name, 'reason' => Enums::reason($reason), 'quantity' => $wanted->formatted(), 'batch' => $batchNo,
            ], array_filter(['note' => $note]));
        });
    }

    /** Set what is really on the shelf; the difference becomes a "stock count" movement. */
    public function count(Item $item, string $counted, ?string $batchNo = null, ?string $note = null): ?Quantity
    {
        Gate::authorize(Permissions::STOCK_ADJUST);

        $real = $this->quantity($counted, allowZero: true);

        if ($item->tracks_expiry && blank($batchNo)) {
            throw new InventoryException("{$item->name} is kept by batch: count one batch at a time.");
        }

        return DB::connection('clinic')->transaction(function () use ($item, $real, $batchNo, $note): ?Quantity {
            $item = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $recorded = $item->tracks_expiry ? $this->ledger->batchQuantity($item, (string) $batchNo) : $item->quantity;
            $difference = $real->minus($recorded);

            if ($difference->isZero()) {
                return null;
            }

            if (blank($note)) {
                throw new InventoryException("The count differs by {$difference->formatted()} {$item->unit}: say why, for example \"broken bottle not recorded\".");
            }

            // Found stock is valued at today's average cost.
            $value = $difference->isPositive() ? $difference->shareOf($item->stock_value, $item->quantity) : null;
            $expires = $item->tracks_expiry ? $this->expiryOf($item, (string) $batchNo) : null;

            $this->ledger->post($item, $difference, $value, 'count', batchNo: $item->tracks_expiry ? $batchNo : null, expiresOn: $expires, note: $note);

            $this->audit->record('stock.counted', $item, ['quantity' => $recorded->formatted()], ['quantity' => $real->formatted()], array_filter(['batch' => $batchNo, 'note' => $note]));

            return $difference;
        });
    }

    public function resolve(Shortfall $shortfall, string $note): void
    {
        Gate::authorize(Permissions::STOCK_ADJUST);

        if (blank($note)) {
            throw new InventoryException('Say how it was sorted out, for example "purchase entered late".');
        }

        DB::connection('clinic')->transaction(function () use ($shortfall, $note): void {
            $shortfall = Shortfall::whereKey($shortfall->id)->lockForUpdate()->firstOrFail();

            if ($shortfall->resolved_at !== null) {
                throw new InventoryException('This is already sorted out.');
            }

            $shortfall->forceFill(['resolved_at' => now(), 'resolved_by' => Auth::id(), 'resolve_note' => mb_substr(trim($note), 0, 255)])->save();

            $this->audit->record('stock.shortfall_resolved', $shortfall->item, [], ['item' => $shortfall->item?->name, 'quantity' => $shortfall->quantity->formatted()], ['note' => trim($note)]);
        });
    }

    private function quantity(string $value, bool $allowZero = false): Quantity
    {
        try {
            $quantity = Quantity::fromDecimal($value);
        } catch (InvalidArgumentException) {
            throw new InventoryException('Enter the quantity as a number, for example 5 or 2.5.');
        }

        if ($quantity->isNegative() || (! $allowZero && $quantity->isZero())) {
            throw new InventoryException($allowZero ? 'A count cannot be negative.' : 'The quantity must be more than zero.');
        }

        return $quantity;
    }

    private function expiryOf(Item $item, string $batchNo): ?string
    {
        foreach ($this->ledger->batches($item) as $batch) {
            if ($batch['batch_no'] === $batchNo) {
                return $batch['expires_on'];
            }
        }

        return DB::connection('clinic')->table('inv_movements')->where('item_id', $item->id)->where('batch_no', $batchNo)->min('expires_on');
    }
}
