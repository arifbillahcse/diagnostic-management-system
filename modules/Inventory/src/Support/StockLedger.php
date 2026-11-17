<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Movement;

/**
 * The only way stock changes. Each change is one movement row; the item's
 * stored quantity and value are kept in step under a row lock, so two
 * counters taking the last tube at once cannot both succeed.
 *
 * Valued at average cost: stock coming in brings its purchase value; stock
 * going out takes its share of the value on hand (all of it for the last unit,
 * so no rounding is left behind).
 */
final class StockLedger
{
    /**
     * @param  Money|null  $value  required for stock coming in; worked out at average cost for stock going out when null
     *
     * @throws InventoryException when there is not enough in stock (or in the batch)
     */
    public function post(
        Item $item,
        Quantity $quantity,
        ?Money $value,
        string $reason,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $branchId = null,
        ?string $batchNo = null,
        ?string $expiresOn = null,
        ?string $note = null,
        ?CarbonInterface $at = null,
    ): Movement {
        $db = DB::connection('clinic');

        if ($db->transactionLevel() === 0) {
            throw new LogicException('Stock must change inside the transaction of the thing that changes it.');
        }

        if ($quantity->isZero()) {
            throw new InventoryException('A stock movement needs a quantity.');
        }

        $item = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();

        if ($quantity->isPositive()) {
            if ($value === null || $value->isNegative()) {
                throw new LogicException('Stock coming in needs its value.');
            }
        } else {
            $out = $quantity->abs();

            if ($out->greaterThan($item->quantity)) {
                throw new InventoryException("Only {$item->quantity->formatted()} {$item->unit} of {$item->name} is in stock.");
            }

            if ($batchNo !== null) {
                $inBatch = $this->batchQuantity($item, $batchNo);

                if ($out->greaterThan($inBatch)) {
                    throw new InventoryException("Only {$inBatch->formatted()} {$item->unit} of {$item->name} is left in batch {$batchNo}.");
                }
            }

            $value = $out->equals($item->quantity)
                ? $item->stock_value->negate()
                : ($value === null ? $out->shareOf($item->stock_value, $item->quantity)->negate() : $value);

            // Never leave stock worth less than nothing.
            if ($item->stock_value->plus($value)->isNegative()) {
                $value = $item->stock_value->negate();
            }
        }

        $movement = Movement::create([
            'item_id' => $item->id,
            'branch_id' => $branchId,
            'quantity' => $quantity,
            'value' => $value,
            'reason' => $reason,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'batch_no' => $batchNo,
            'expires_on' => $expiresOn,
            'note' => $note !== null ? mb_substr(trim($note), 0, 255) : null,
            'created_by' => Auth::id(),
            'occurred_at' => $at ?? now(),
        ]);

        $item->forceFill([
            'quantity' => $item->quantity->plus($quantity),
            'stock_value' => $item->stock_value->plus($value),
        ])->saveQuietly(); // the movement is the record; no audit line per stock change

        return $movement;
    }

    public function batchQuantity(Item $item, string $batchNo): Quantity
    {
        $sum = DB::connection('clinic')->table('inv_movements')->where('item_id', $item->id)->where('batch_no', $batchNo)->sum('quantity');

        return self::sumToQuantity($sum);
    }

    /**
     * Put every item's stored quantity and value back to the sum of its
     * movements, and say which ones were wrong.
     *
     * @return array<string, array{stored: string, movements: string}>
     */
    public function reconcile(): array
    {
        $wrong = [];
        $sums = DB::connection('clinic')->table('inv_movements')
            ->selectRaw('item_id, SUM(quantity) as q, SUM(value) as v')
            ->groupBy('item_id')
            ->get()
            ->keyBy('item_id');

        foreach (Item::query()->orderBy('id')->get() as $item) {
            $row = $sums->get($item->id);
            $quantity = $row ? self::sumToQuantity($row->q) : Quantity::zero();
            $value = $row ? MoneyCast::fromDatabase($row->v) : Money::zero();

            if (! $quantity->equals($item->quantity) || ! $value->equals($item->stock_value)) {
                $wrong[$item->name] = [
                    'stored' => "{$item->quantity->formatted()} / {$item->stock_value->toDecimal()}",
                    'movements' => "{$quantity->formatted()} / {$value->toDecimal()}",
                ];
                $item->forceFill(['quantity' => $quantity, 'stock_value' => $value])->saveQuietly();
            }
        }

        return $wrong;
    }

    /** A database SUM of a DECIMAL(14,3) column, read exactly. */
    private static function sumToQuantity(mixed $sum): Quantity
    {
        if ($sum === null) {
            return Quantity::zero();
        }

        // SQLite may return a float or an integer for SUM(); MySQL returns a
        // decimal string. Format to three places before reading it exactly.
        $text = is_string($sum) ? $sum : number_format((float) $sum, 3, '.', '');

        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/', $text, $m)) {
            $text = $m[1].'.'.substr(str_pad($m[2] ?? '', 3, '0'), 0, 3);
        }

        return Quantity::fromDecimal($text);
    }
}
