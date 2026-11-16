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
        $schema = Schema::connection('clinic');

        // The doctor's own code, as clinics print it beside the name.
        $schema->table('fd_referral_doctors', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('public_id');
        });

        // Where the patient goes for this kind of service, printed on the
        // invoice, for example "USG (Room 210)" or "Sample collection (Room 106)".
        $schema->table('fd_service_categories', function (Blueprint $table) {
            $table->string('room', 80)->nullable()->after('sort_order');
        });

        // When the report is promised.
        $schema->table('fd_orders', function (Blueprint $table) {
            $table->timestamp('delivery_at')->nullable()->after('ordered_at');
        });

        // Supplies that are charged with a service: a tube, a needle.
        $schema->create('fd_service_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('fd_services')->cascadeOnDelete();
            $table->foreignId('addon_service_id')->constrained('fd_services')->restrictOnDelete();
            $table->unique(['service_id', 'addon_service_id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('clinic');

        $schema->dropIfExists('fd_service_addons');
        $schema->table('fd_orders', fn (Blueprint $table) => $table->dropColumn('delivery_at'));
        $schema->table('fd_service_categories', fn (Blueprint $table) => $table->dropColumn('room'));
        $schema->table('fd_referral_doctors', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
