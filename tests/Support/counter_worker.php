<?php

declare(strict_types=1);

/*
| Helper process for the simultaneous-numbering test.
| Usage: php counter_worker.php <clinic id> <how many numbers>
| Prints one invoice number per line.
*/

use App\Core\Branches\Branch;
use App\Core\Numbering\NumberGenerator;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$app->make(ClinicManager::class)->activate(Clinic::findOrFail((int) $argv[1]));
$branch = Branch::firstOrFail();

for ($i = 0; $i < (int) $argv[2]; $i++) {
    echo DB::connection('clinic')->transaction(
        fn (): string => $app->make(NumberGenerator::class)->generate('invoice', $branch),
    ), PHP_EOL;
}
