<?php

declare(strict_types=1);

namespace Modules\Finance\Console;

use App\Core\Modules\ModuleState;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Console\Command;
use Modules\Finance\Support\Commission;
use Modules\Finance\Support\Posting;
use Modules\FrontDesk\Enums\RefundStatus;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Models\Refund;

final class SyncCommand extends Command
{
    protected $signature = 'finance:sync {--clinic= : Only this clinic (numeric ID)}';

    protected $description = 'Post any payments and refunds missing from the accounts, and bring referral commission up to date. Safe to run again';

    public function handle(ClinicManager $manager): int
    {
        $posted = 0;

        $manager->eachClinic(function () use (&$posted): void {
            if (app(ModuleState::class)->isEnabled('finance')) {
                $posted += self::catchUp();
            }
        }, $this->option('clinic') ? (int) $this->option('clinic') : null);

        $this->info("{$posted} missing account entr".($posted === 1 ? 'y' : 'ies').' posted.');

        return self::SUCCESS;
    }

    /** Run for the active clinic. Returns how many entries were missing. */
    public static function catchUp(): int
    {
        $posting = app(Posting::class);
        $posted = 0;

        $missing = fn (string $source, string $table) => fn ($query) => $query
            ->whereNotIn("{$table}.id", fn ($sub) => $sub->select('source_id')->from('fin_account_entries')->where('source_type', $source));

        Payment::query()->where($missing('payment', 'fd_payments'))->chunkById(500, function ($payments) use ($posting, &$posted): void {
            foreach ($payments as $payment) {
                $posting->payment($payment);
                $posted++;
            }
        });

        Refund::query()->where('status', RefundStatus::Approved->value)->where($missing('refund', 'fd_refunds'))->chunkById(500, function ($refunds) use ($posting, &$posted): void {
            foreach ($refunds as $refund) {
                $posting->refund($refund);
                $posted++;
            }
        });

        // Commission: every invoice of an order that names a referral doctor.
        Invoice::query()
            ->whereIn('order_id', fn ($sub) => $sub->select('id')->from('fd_orders')->whereNotNull('referral_doctor_id'))
            ->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    app(Commission::class)->settle($invoice);
                }
            });

        return $posted;
    }
}
