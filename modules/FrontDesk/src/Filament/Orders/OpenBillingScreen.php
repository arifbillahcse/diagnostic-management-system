<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Orders;

use Filament\Resources\Pages\Page;
use Modules\FrontDesk\Filament\NewBill;

/**
 * The old order form's address (/orders/create). The billing screen replaced
 * the form, so old bookmarks and links land there.
 */
class OpenBillingScreen extends Page
{
    protected static string $resource = OrderResource::class;

    protected string $view = 'frontdesk::redirect';

    public function mount(): void
    {
        $this->redirect(NewBill::getUrl());
    }
}
