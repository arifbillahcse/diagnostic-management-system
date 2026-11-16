<?php

declare(strict_types=1);

/*
| Helper process for the simultaneous-payment test.
| Usage: php payment_worker.php <clinic id> <invoice id> <amount>
| Prints "paid" or "refused: <message>".
*/

use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Money\Money;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Modules\FrontDesk\Actions\RecordPayment;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Support\FrontDeskException;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$app->make(ClinicManager::class)->activate(Clinic::findOrFail((int) $argv[1]));
Auth::setUser(User::whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->firstOrFail());

try {
    $app->make(RecordPayment::class)->handle(Invoice::findOrFail((int) $argv[2]), Money::fromDecimal($argv[3]), PaymentMethod::Bkash);
    echo 'paid';
} catch (FrontDeskException $e) {
    echo 'refused: '.$e->getMessage();
}
