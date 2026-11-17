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

        // One test can use several items, and one item can come out of several
        // batches, so "the same cause never changes stock twice" now uses a key
        // made for each change: "purchase_line:12", "use:345:7:L42", ...
        $schema->table('inv_movements', function (Blueprint $table) {
            $table->string('posting_key', 100)->nullable()->after('source_id');
        });

        DB::connection('clinic')->table('inv_movements')->whereNotNull('source_type')->orderBy('id')->each(function ($row): void {
            DB::connection('clinic')->table('inv_movements')->where('id', $row->id)->update(['posting_key' => "{$row->source_type}:{$row->source_id}"]);
        });

        $schema->table('inv_movements', function (Blueprint $table) {
            $table->dropUnique(['source_type', 'source_id']);
            $table->unique('posting_key');
            $table->index(['source_type', 'source_id']);
        });

        // What each test or service takes out of stock every time it is ordered.
        $schema->create('inv_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('fd_services')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('inv_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();

            $table->unique(['service_id', 'item_id']);
        });

        // A test was ordered but the stock it uses was not on hand (usually a
        // purchase not entered yet). Billing never waits for stock; the gap is
        // kept here for staff to sort out.
        $schema->create('inv_shortfalls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('fd_order_items')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('inv_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('resolve_note')->nullable();
            $table->timestamps();

            $table->unique(['order_item_id', 'item_id']);
            $table->index(['resolved_at', 'created_at']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('clinic');
        $schema->dropIfExists('inv_shortfalls');
        $schema->dropIfExists('inv_usages');
        $schema->table('inv_movements', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropUnique(['posting_key']);
            $table->unique(['source_type', 'source_id']);
            $table->dropColumn('posting_key');
        });
    }
};
