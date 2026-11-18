<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A clinic's trial end and paid-until dates. Existing clinics get neither,
| so nothing changes for them until the platform sets a date.
*/
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->table('clinics', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('plan_id');
            $table->date('paid_until')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('clinics', function (Blueprint $table) {
            $table->dropColumn(['trial_ends_at', 'paid_until']);
        });
    }
};
