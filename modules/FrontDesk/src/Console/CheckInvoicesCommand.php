<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Console;

use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Console\Command;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Support\InvoiceCalculator;

final class CheckInvoicesCommand extends Command
{
    protected $signature = 'frontdesk:check-invoices {--clinic= : Only this clinic (numeric ID)}';

    protected $description = 'Recalculate every invoice from its lines, payments, and refunds, and report any stored total that differs. Changes nothing';

    public function handle(ClinicManager $manager, InvoiceCalculator $calculator): int
    {
        $checked = $wrong = 0;

        $manager->eachClinic(function (Clinic $clinic) use ($calculator, &$checked, &$wrong): void {
            if (! app(ModuleState::class)->isInstalled('frontdesk')) {
                return;
            }

            Invoice::query()->chunkById(500, function ($invoices) use ($clinic, $calculator, &$checked, &$wrong): void {
                foreach ($invoices as $invoice) {
                    $checked++;
                    $differences = $calculator->differences($invoice);

                    if ($differences !== []) {
                        $wrong++;
                        $details = collect($differences)->map(fn (array $d, string $field): string => "{$field}: stored {$d['stored']}, calculated {$d['calculated']}")->implode('; ');
                        $this->error("Clinic #{$clinic->id} invoice {$invoice->invoice_no}: {$details}");
                    }
                }
            });
        }, $this->option('clinic') ? (int) $this->option('clinic') : null);

        $this->line("{$checked} invoice(s) checked, {$wrong} with differences.");

        return $wrong === 0 ? self::SUCCESS : self::FAILURE;
    }
}
