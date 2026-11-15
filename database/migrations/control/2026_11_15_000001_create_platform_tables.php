<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control database: clinics, their domains, plans, and module entitlements.
 * Holds no patient data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // module_key "*" means every module.
        Schema::create('plan_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('module_key', 40);
            $table->unique(['plan_id', 'module_key']);
        });

        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name');
            $table->string('status', 20)->default('trial')->index();
            $table->string('db_name', 64)->unique();
            $table->string('db_host')->nullable();
            $table->string('db_port', 10)->nullable();
            $table->string('db_username', 32)->nullable();
            $table->text('db_password')->nullable(); // encrypted
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('clinic_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // Add-ons, trials, and overrides on top of the plan.
        Schema::create('clinic_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('module_key', 40);
            $table->string('source', 20)->default('addon'); // addon, trial
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['clinic_id', 'module_key']);
        });

        // Deliberately no foreign key: the row must survive a failed setup
        // that removes the clinic again.
        Schema::create('provisioning_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id')->nullable()->index();
            $table->string('step', 60);
            $table->string('status', 20);
            $table->text('message')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('provisioning_log');
        Schema::dropIfExists('clinic_modules');
        Schema::dropIfExists('clinic_domains');
        Schema::dropIfExists('clinics');
        Schema::dropIfExists('plan_modules');
        Schema::dropIfExists('plans');
    }
};
