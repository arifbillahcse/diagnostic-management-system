<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Core\Auth\CorePermissions;
use App\Core\Tenancy\ClinicContext;
use App\Platform\Billing\SubscriptionInvoice;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/** The clinic's own plan, subscription dates, and invoices from the provider. */
class Subscription extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 95;

    protected static ?string $title = 'Subscription';

    protected static ?string $slug = 'subscription';

    protected string $view = 'filament.pages.subscription';

    public static function canAccess(): bool
    {
        return Gate::allows(CorePermissions::SETTINGS_MANAGE);
    }

    /** @return Collection<int, SubscriptionInvoice> */
    public function getInvoicesProperty(): Collection
    {
        return SubscriptionInvoice::where('clinic_id', app(ClinicContext::class)->id())->with('payments')->orderByDesc('created_at')->limit(50)->get();
    }
}
