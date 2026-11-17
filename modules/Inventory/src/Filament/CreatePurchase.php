<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use App\Core\Branches\CurrentBranch;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Actions\ManagePurchases;
use Modules\Inventory\Models\Supplier;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected static ?string $title = 'Record a purchase';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return Guard::run(null, fn () => app(ManagePurchases::class)->record(
            app(CurrentBranch::class)->get(),
            Supplier::findOrFail($data['supplier_id']),
            (string) $data['purchase_date'],
            array_values($data['lines'] ?? []),
            $data['supplier_bill_no'] ?? null,
            $data['note'] ?? null,
        ), 'Purchase recorded') ?? throw new Halt;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return null; // Guard already said so
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
