<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        // Lets "collections by payment method over a period" be answered
        // from the index alone, without reading each payment row.
        Schema::connection('clinic')->table('fd_payments', function (Blueprint $table) {
            $table->index(['received_at', 'method', 'amount']);
        });
    }

    public function down(): void
    {
        Schema::connection('clinic')->table('fd_payments', fn (Blueprint $table) => $table->dropIndex(['received_at', 'method', 'amount']));
    }
};
