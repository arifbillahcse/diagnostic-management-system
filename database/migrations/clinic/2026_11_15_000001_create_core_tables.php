<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core tables of a clinic database. See docs/schema.md, section 3.
 */
return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        Schema::connection('clinic')->create('branches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('clinic')->create('users', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 30)->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->foreignId('default_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('clinic')->create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::connection('clinic')->create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('module_key', 40)->index();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::connection('clinic')->create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        // branch_id null means the role applies in every branch.
        Schema::connection('clinic')->create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'branch_id']);
        });

        Schema::connection('clinic')->create('role_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('limit_key', 80);
            $table->string('value');
            $table->timestamps();
            $table->unique(['role_id', 'limit_key']);
        });

        Schema::connection('clinic')->create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 60);
            $table->string('key', 80);
            $table->text('value')->nullable(); // JSON, or ciphertext when is_encrypted
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
            $table->unique(['group', 'key']);
        });

        Schema::connection('clinic')->create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('module_key', 40)->unique();
            $table->string('version', 20);
            $table->string('status', 20); // enabled, disabled
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        // branch_id 0 means clinic-wide. A real value, not null, so the
        // unique index can guarantee one row per counter.
        Schema::connection('clinic')->create('counters', function (Blueprint $table) {
            $table->id();
            $table->string('counter_key', 40);
            $table->unsignedBigInteger('branch_id')->default(0);
            $table->string('period', 12)->default('');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique(['counter_key', 'branch_id', 'period']);
        });

        Schema::connection('clinic')->create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('actor_type', 20); // user, system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->index(['entity_type', 'entity_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index('occurred_at');
            $table->index(['action', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_logs', 'counters', 'modules', 'settings', 'role_limits',
            'user_roles', 'role_permissions', 'permissions', 'roles', 'users', 'branches',
        ] as $table) {
            Schema::connection('clinic')->dropIfExists($table);
        }
    }
};
