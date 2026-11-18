<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Billing clinics for their subscription: invoices per period and the
| payments received. Invoice amounts are never edited (a wrong invoice is
| voided and issued again); payments are append-only.
*/
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        $schema = Schema::connection('control');

        // Business numbers for the platform, issued under a row lock.
        $schema->create('platform_counters', function (Blueprint $table) {
            $table->id();
            $table->string('counter_key', 40);
            $table->string('period', 10)->default('');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique(['counter_key', 'period']);
        });

        $schema->create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('invoice_no', 40)->unique();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('plan_name');                       // copied: later plan edits do not change old invoices
            $table->string('billing_period', 10);              // monthly, yearly
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 12, 2);
            $table->string('note')->nullable();                // a discount or anything to print
            $table->string('status', 10);                      // issued, paid, void
            $table->date('due_on');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinic_id', 'status']);
        });

        $schema->create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('subscription_invoices')->restrictOnDelete();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);                      // bkash, nagad, rocket, bank, cash, other
            $table->string('reference', 100)->nullable();
            $table->date('received_on');
            $table->foreignId('recorded_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // MySQL refuses changes to recorded payments, as it does for clinic money.
        $db = DB::connection('control');
        if ($db->getDriverName() === 'mysql') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $trigger = 'subscription_payments_no_'.strtolower($event);
                $db->unprepared("DROP TRIGGER IF EXISTS {$trigger}");
                $db->unprepared("CREATE TRIGGER {$trigger} BEFORE {$event} ON subscription_payments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'subscription_payments is append-only'");
            }
        }
    }
};
