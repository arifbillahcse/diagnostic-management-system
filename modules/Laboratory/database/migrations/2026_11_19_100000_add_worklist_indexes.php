<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes found necessary by timing the screens against two years of data
 * (docs/performance.md).
 */
return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        Schema::connection('clinic')->table('lab_work_items', function (Blueprint $table) {
            $table->index(['status', 'released_at']); // worklist tabs, menu badge, "released today"
            $table->index(['order_id', 'status']);    // "does this order have a released result?"
        });

        Schema::connection('clinic')->table('lab_samples', function (Blueprint $table) {
            $table->index(['status', 'id']); // samples to collect, menu badge
        });
    }

    public function down(): void
    {
        Schema::connection('clinic')->table('lab_work_items', function (Blueprint $table) {
            $table->dropIndex(['status', 'released_at']);
            $table->dropIndex(['order_id', 'status']);
        });
        Schema::connection('clinic')->table('lab_samples', fn (Blueprint $table) => $table->dropIndex(['status', 'id']));
    }
};
