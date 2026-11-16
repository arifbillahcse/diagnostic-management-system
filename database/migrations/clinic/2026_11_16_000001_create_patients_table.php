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
        Schema::connection('clinic')->create('patients', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('patient_code', 30)->unique();
            $table->string('name');
            $table->string('sex', 10); // male, female, other
            // Always stored. When the patient gave an age instead of a birth
            // date, it is calculated from that age and flagged as estimated.
            $table->date('date_of_birth');
            $table->boolean('dob_is_estimated')->default(false);
            $table->string('phone', 30)->nullable();
            $table->string('phone_normalized', 20)->nullable()->index();
            $table->string('address')->nullable();
            $table->string('email')->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('registered_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::connection('clinic')->dropIfExists('patients');
    }
};
