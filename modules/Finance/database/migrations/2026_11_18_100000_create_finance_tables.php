<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance tables. See docs/schema.md, section 8.
 */
return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        $schema = Schema::connection('clinic');

        // Where money is held: the cash box, a bank account, a bKash number.
        $schema->create('fin_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name')->unique();
            $table->string('type', 20); // cash, bkash, nagad, card, bank
            // Payments by a method go to the default account of that type.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_default']);
        });

        // Every movement of money in or out of an account. Append-only: a
        // mistake is corrected with an opposite entry. An account's balance
        // is the sum of its entries.
        $schema->create('fin_account_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('fin_accounts')->restrictOnDelete();
            $table->decimal('amount', 12, 2); // positive in, negative out
            $table->timestamp('occurred_at');
            // What caused it: payment, refund, expense, expense_void, payout,
            // transfer_out, transfer_in, opening.
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->string('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            // One entry per cause, so posting the same thing twice is impossible.
            $table->unique(['source_type', 'source_id']);
            $table->index(['account_id', 'occurred_at']);
            $table->index('occurred_at');
        });

        $schema->create('fin_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_account_id')->constrained('fin_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('fin_accounts')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });

        $schema->create('fin_expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('fin_expenses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('expense_no', 40)->unique();
            $table->foreignId('category_id')->constrained('fin_expense_categories')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('fin_accounts')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('expense_date'); // the clinic's local date
            $table->string('payee')->nullable();
            $table->string('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'expense_date']);
            $table->index(['category_id', 'expense_date']);
        });

        // A doctor's commission: one default rule (service_id null) and
        // optional rules for particular services.
        $schema->create('fin_commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_doctor_id')->constrained('fd_referral_doctors')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('fd_services')->cascadeOnDelete();
            $table->string('kind', 10); // percent, fixed
            $table->decimal('value', 12, 2);
            $table->timestamps();

            $table->index(['referral_doctor_id', 'service_id']);
        });

        // Commission earned (positive) or taken back (negative) for one
        // order item. Append-only. What a doctor is owed is the sum of
        // their entries less their payouts.
        $schema->create('fin_commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_doctor_id')->constrained('fd_referral_doctors')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('fd_order_items')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('fd_invoices')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason'); // "Invoice paid", "Test cancelled", ...
            $table->timestamp('created_at');

            $table->index(['referral_doctor_id', 'created_at']);
            $table->index('order_item_id');
            $table->index('invoice_id');
        });

        $schema->create('fin_commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('payout_no', 40)->unique();
            $table->foreignId('referral_doctor_id')->constrained('fd_referral_doctors')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('fin_accounts')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('paid_at');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->index(['referral_doctor_id', 'paid_at']);
        });

        if (DB::connection('clinic')->getDriverName() === 'mysql') {
            $db = DB::connection('clinic');
            $db->statement('ALTER TABLE fin_expenses ADD CONSTRAINT fin_expenses_amount_chk CHECK (amount > 0)');
            $db->statement('ALTER TABLE fin_transfers ADD CONSTRAINT fin_transfers_chk CHECK (amount > 0 AND from_account_id <> to_account_id)');
            $db->statement('ALTER TABLE fin_commission_payouts ADD CONSTRAINT fin_payouts_amount_chk CHECK (amount > 0)');
            $db->statement('ALTER TABLE fin_commission_rules ADD CONSTRAINT fin_rules_value_chk CHECK (value >= 0)');
        }
    }

    public function down(): void
    {
        foreach ([
            'fin_commission_payouts', 'fin_commission_entries', 'fin_commission_rules', 'fin_expenses',
            'fin_expense_categories', 'fin_transfers', 'fin_account_entries', 'fin_accounts',
        ] as $table) {
            Schema::connection('clinic')->dropIfExists($table);
        }
    }
};
