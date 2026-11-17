<?php

declare(strict_types=1);

namespace Modules\Inventory\Http;

use App\Core\Branches\Branch;
use App\Core\Documents\Letterhead;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Permissions;

/** A printable copy of a purchase, for the supplier file. */
final class PurchasePrintController
{
    public function __invoke(Purchase $purchase, Letterhead $letterhead, ClinicContext $clinics): View
    {
        Gate::authorize(Permissions::STOCK_VIEW, $purchase->branch_id);

        return view('inventory::print.purchase', [
            'purchase' => $purchase->load(['supplier', 'lines.item', 'creator']),
            'clinic' => $letterhead->data(Branch::find($purchase->branch_id)),
            'tz' => $clinics->timezone(),
        ]);
    }
}
