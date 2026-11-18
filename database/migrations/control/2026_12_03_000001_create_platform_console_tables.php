<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The platform console (Softorio's own admin area): its users, who are not
| clinic users, and a log of everything they do.
*/
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        $schema = Schema::connection('control');

        $schema->create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->text('app_authentication_secret')->nullable();       // two-step sign-in, required
            $table->text('app_authentication_recovery_codes')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // Append-only: what a platform user did, to which clinic.
        $schema->create('platform_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->unsignedBigInteger('clinic_id')->nullable()->index();
            $table->string('action', 60);
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
        });
    }
};
