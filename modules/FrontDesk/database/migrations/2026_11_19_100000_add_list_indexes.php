<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes found necessary by timing the screens against two years of data
 * (docs/performance.md). The first indexes all started with the branch,
 * which lists and reports covering every branch could not use.
 */
return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        Schema::connection('clinic')->table('fd_invoices', function (Blueprint $table) {
            $table->index(['status', 'issued_at']); // dues list, dues total
        });

        Schema::connection('clinic')->table('fd_orders', function (Blueprint $table) {
            $table->index('ordered_at'); // sales reports over a period
        });
    }

    public function down(): void
    {
        Schema::connection('clinic')->table('fd_invoices', fn (Blueprint $table) => $table->dropIndex(['status', 'issued_at']));
        Schema::connection('clinic')->table('fd_orders', fn (Blueprint $table) => $table->dropIndex(['ordered_at']));
    }
};
