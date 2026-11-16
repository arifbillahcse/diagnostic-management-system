<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Front desk tables. See docs/schema.md, section 4.
 */
return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        $schema = Schema::connection('clinic');

        $schema->create('fd_service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $schema->create('fd_services', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('type', 20); // lab_test, imaging, package, other
            $table->foreignId('category_id')->nullable()->constrained('fd_service_categories')->restrictOnDelete();
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index(['is_active', 'type']);
        });

        $schema->create('fd_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_service_id')->constrained('fd_services')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('fd_services')->restrictOnDelete();
            $table->unique(['package_service_id', 'service_id']);
        });

        $schema->create('fd_referral_doctors', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name');
            $table->string('degree')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        $schema->create('fd_orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('order_no', 40)->unique();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('referral_doctor_id')->nullable()->constrained('fd_referral_doctors')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 20);
            $table->timestamp('ordered_at');
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'ordered_at']);
            $table->index(['patient_id', 'ordered_at']);
            $table->index(['branch_id', 'status', 'ordered_at']);
        });

        $schema->create('fd_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('fd_orders')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('fd_services')->restrictOnDelete();
            // Name, type, and price are copied here so a later catalog edit
            // cannot change what was ordered.
            $table->string('service_name');
            $table->string('service_type', 20);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            // Set on the tests that came in as part of a package.
            $table->foreignId('package_item_of')->nullable()->constrained('fd_order_items')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });

        $schema->create('fd_invoices', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('invoice_no', 40)->unique();
            $table->foreignId('order_id')->unique()->constrained('fd_orders')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            // Stored for speed. The truth is the lines, payments, and
            // refunds; `frontdesk:check-invoices` compares the two.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('adjustment_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->decimal('refunded_total', 12, 2)->default(0);
            $table->decimal('due_amount', 12, 2)->default(0);
            $table->string('status', 20);
            $table->string('discount_reason')->nullable();
            $table->foreignId('discount_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'issued_at']);
            $table->index(['patient_id', 'issued_at']);
        });

        // Append-only. A change after the invoice is issued is a new line.
        $schema->create('fd_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('fd_invoices')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('fd_order_items')->restrictOnDelete();
            $table->string('kind', 20); // charge, discount, adjustment
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->index('created_at');
        });

        $schema->create('fd_cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 12, 2)->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['opened_by', 'status']);
            $table->index(['branch_id', 'opened_at']);
        });

        // Append-only. A mistake is corrected with a refund, never an edit.
        $schema->create('fd_payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('receipt_no', 40)->unique();
            $table->foreignId('invoice_id')->constrained('fd_invoices')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 20); // cash, bkash, nagad, card, bank
            $table->string('reference')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained('fd_cash_sessions')->restrictOnDelete();
            $table->timestamp('received_at');
            // Lets a repeated request (double click, gateway retry) be
            // recognised and answered with the first payment.
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('created_at');

            $table->index('received_at');
        });

        $schema->create('fd_refunds', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('refund_no', 40)->nullable()->unique(); // issued on approval
            $table->foreignId('invoice_id')->constrained('fd_invoices')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->string('reference')->nullable();
            $table->string('reason');
            $table->string('status', 20); // pending, approved, rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->foreignId('cash_session_id')->nullable()->constrained('fd_cash_sessions')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'requested_at']);
            $table->index('decided_at');
        });

        $schema->create('fd_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('summary_date');
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('patients_count')->default(0);
            $table->decimal('billed_total', 12, 2)->default(0);
            $table->decimal('collected_total', 12, 2)->default(0);
            $table->decimal('refunded_total', 12, 2)->default(0);
            $table->decimal('due_total', 12, 2)->default(0);
            $table->timestamp('rebuilt_at');

            $table->unique(['summary_date', 'branch_id']);
        });

        // A last line of defence under the application rules. SQLite (fast
        // tests only) cannot add constraints to an existing table.
        if (DB::connection('clinic')->getDriverName() === 'mysql') {
            $db = DB::connection('clinic');
            $db->statement('ALTER TABLE fd_services ADD CONSTRAINT fd_services_price_chk CHECK (price >= 0)');
            $db->statement('ALTER TABLE fd_payments ADD CONSTRAINT fd_payments_amount_chk CHECK (amount > 0)');
            $db->statement('ALTER TABLE fd_refunds ADD CONSTRAINT fd_refunds_amount_chk CHECK (amount > 0)');
            $db->statement('ALTER TABLE fd_invoices ADD CONSTRAINT fd_invoices_paid_chk CHECK (paid_total >= 0 AND refunded_total >= 0 AND refunded_total <= paid_total)');
            $db->statement('ALTER TABLE fd_order_items ADD CONSTRAINT fd_order_items_amounts_chk CHECK (unit_price >= 0 AND discount_amount >= 0 AND net_amount >= 0 AND discount_amount <= unit_price)');
        }
    }

    public function down(): void
    {
        foreach ([
            'fd_daily_summaries', 'fd_refunds', 'fd_payments', 'fd_cash_sessions', 'fd_invoice_lines',
            'fd_invoices', 'fd_order_items', 'fd_orders', 'fd_referral_doctors', 'fd_package_items',
            'fd_services', 'fd_service_categories',
        ] as $table) {
            Schema::connection('clinic')->dropIfExists($table);
        }
    }
};
