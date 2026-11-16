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
        // "Commission earned in a period" from the index alone.
        Schema::connection('clinic')->table('fin_commission_entries', function (Blueprint $table) {
            $table->index(['created_at', 'amount']);
            $table->dropIndex(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('clinic')->table('fin_commission_entries', function (Blueprint $table) {
            $table->index('created_at');
            $table->dropIndex(['created_at', 'amount']);
        });
    }
};
