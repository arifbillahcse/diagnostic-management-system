<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Plans (editions) get prices and limits, and three editions are added
| beside the existing "full" plan, which stays as the trial plan with every
| module. Existing clinics keep their plan. Prices are placeholders for the
| owner to set (php artisan plans:set).
*/
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->table('plans', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
            $table->decimal('price_monthly', 12, 2)->default(0)->after('description');
            $table->decimal('price_yearly', 12, 2)->default(0)->after('price_monthly');
            $table->unsignedSmallInteger('max_branches')->nullable()->after('price_yearly'); // null: no limit
            $table->unsignedSmallInteger('sort_order')->default(0)->after('max_branches');
        });

        $db = DB::connection('control');
        $now = now();

        $editions = [
            'basic' => ['Basic', 'Front desk, laboratory, and lab reports', 1, 10, ['frontdesk', 'laboratory', 'reports']],
            'standard' => ['Standard', 'Basic, plus finance, owner reports, SMS, imaging, patient portal, and inventory', 1, 20,
                ['frontdesk', 'laboratory', 'reports', 'finance', 'owner', 'messaging', 'imaging', 'portal', 'inventory']],
            'premium' => ['Premium', 'Every module, including staff and payroll, multi-branch, and lab machines', 3, 30, ['*']],
        ];

        foreach ($editions as $key => [$name, $description, $branches, $order, $modules]) {
            if ($db->table('plans')->where('key', $key)->exists()) {
                continue;
            }

            $id = $db->table('plans')->insertGetId([
                'key' => $key, 'name' => $name, 'description' => $description, 'max_branches' => $branches,
                'sort_order' => $order, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ($modules as $module) {
                $db->table('plan_modules')->insert(['plan_id' => $id, 'module_key' => $module]);
            }
        }

        $db->table('plans')->where('key', 'full')->whereNull('description')
            ->update(['description' => 'Every module. Used for trials.', 'sort_order' => 0]);
    }

    public function down(): void
    {
        Schema::connection('control')->table('plans', function (Blueprint $table) {
            $table->dropColumn(['description', 'price_monthly', 'price_yearly', 'max_branches', 'sort_order']);
        });
    }
};
