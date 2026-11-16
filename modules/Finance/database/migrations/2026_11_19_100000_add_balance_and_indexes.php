<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Found necessary by timing the screens against two years of data
 * (docs/performance.md).
 */
return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        // The balance is kept on the account, updated with every entry, so
        // the dashboard does not add up every entry ever made. The entries
        // remain the truth; `finance:sync` checks the two agree.
        Schema::connection('clinic')->table('fin_accounts', function (Blueprint $table) {
            $table->decimal('balance', 14, 2)->default(0);
        });

        DB::connection('clinic')->statement(
            'UPDATE fin_accounts SET balance = (SELECT COALESCE(SUM(amount), 0) FROM fin_account_entries WHERE fin_account_entries.account_id = fin_accounts.id)'
        );

        Schema::connection('clinic')->table('fin_commission_entries', function (Blueprint $table) {
            $table->index('created_at');                       // commission earned in a period
            $table->index(['referral_doctor_id', 'amount']);   // a doctor's total, from the index alone
        });
    }

    public function down(): void
    {
        Schema::connection('clinic')->table('fin_commission_entries', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['referral_doctor_id', 'amount']);
        });
        Schema::connection('clinic')->table('fin_accounts', fn (Blueprint $table) => $table->dropColumn('balance'));
    }
};
