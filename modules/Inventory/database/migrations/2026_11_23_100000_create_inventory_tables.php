<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        $schema = Schema::connection('clinic');

        // Things the clinic keeps in stock: reagents, tubes, needles, films.
        $schema->create('inv_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('category', 20);              // reagent, consumable, other
            $table->string('unit', 20);                  // piece, ml, box, test, ...
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->boolean('tracks_expiry')->default(false);
            $table->boolean('is_active')->default(true);
            // Kept in step with the movements, checked nightly (inventory:check).
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('stock_value', 14, 2)->default(0);
            $table->timestamps();

            $table->index('name');
            $table->index(['is_active', 'category']);
        });

        $schema->create('inv_suppliers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name')->unique();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Stock coming in. Never edited: a wrong purchase is voided and entered again.
        $schema->create('inv_purchases', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('purchase_no', 40)->unique();
            $table->foreignId('supplier_id')->constrained('inv_suppliers')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('purchase_date');
            $table->string('supplier_bill_no', 60)->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            // Paid: with the finance module on, an expense records the money.
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('expense_id')->nullable(); // fin_expenses.id; no key, finance is optional
            $table->string('payment_note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['purchase_date', 'id']);
            $table->index(['supplier_id', 'purchase_date']);
        });

        $schema->create('inv_purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('inv_purchases')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('inv_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->string('batch_no', 60)->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Every change to stock: in (positive) or out (negative), with its value.
        // Stock on hand is the sum. Append-only.
        $schema->create('inv_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('value', 14, 2);
            $table->string('reason', 20);                // purchase, purchase_void, ...
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('batch_no', 60)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            // The same cause can never change stock twice.
            $table->unique(['source_type', 'source_id']);
            $table->index(['item_id', 'occurred_at']);
            $table->index(['item_id', 'batch_no']);
            $table->index(['reason', 'occurred_at']);
        });

        if (DB::connection('clinic')->getDriverName() === 'mysql') {
            $db = DB::connection('clinic');
            $db->statement('ALTER TABLE inv_purchase_lines ADD CONSTRAINT inv_purchase_lines_amounts_chk CHECK (quantity > 0 AND unit_cost >= 0 AND line_total >= 0)');
            $db->statement('ALTER TABLE inv_items ADD CONSTRAINT inv_items_stock_chk CHECK (quantity >= 0 AND reorder_level >= 0)');
        }
    }

    public function down(): void
    {
        foreach (['inv_movements', 'inv_purchase_lines', 'inv_purchases', 'inv_suppliers', 'inv_items'] as $table) {
            Schema::connection('clinic')->dropIfExists($table);
        }
    }
};
