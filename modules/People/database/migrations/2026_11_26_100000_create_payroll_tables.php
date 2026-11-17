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

        // Money given to staff ahead of their salary. Never edited: a wrong one is voided.
        $schema->create('ppl_advances', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('advance_no', 40)->unique();
            $table->foreignId('staff_id')->constrained('ppl_staff')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('given_on');
            $table->string('reason');
            $table->unsignedBigInteger('expense_id')->nullable(); // finance, no key
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'voided_at']);
        });

        // One salary run per branch per month: draft → approved → paid, or cancelled.
        $schema->create('ppl_salary_runs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('run_no', 40)->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->char('month', 7);                       // 2026-11
            $table->string('status', 20);
            // "branch:month" while the run is not cancelled, so only one live run exists.
            $table->string('live_key', 40)->nullable()->unique();
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_advances', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('paid_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('expense_id')->nullable(); // finance, no key
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'month']);
        });

        // One person in a run. Changed only while the run is a draft.
        $schema->create('ppl_salary_lines', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('run_id')->constrained('ppl_salary_runs')->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('ppl_staff')->restrictOnDelete();
            $table->decimal('monthly_salary', 12, 2);
            $table->unsignedTinyInteger('days_in_month');
            $table->unsignedTinyInteger('days_employed');
            $table->decimal('days_worked', 4, 1)->default(0);
            $table->unsignedTinyInteger('days_absent')->default(0);
            $table->unsignedTinyInteger('days_leave')->default(0);
            $table->unsignedTinyInteger('days_off')->default(0);
            $table->unsignedTinyInteger('days_unmarked')->default(0);
            $table->decimal('gross', 12, 2);
            $table->decimal('deduction', 12, 2)->default(0);
            $table->string('deduction_note')->nullable();
            $table->decimal('advance_recovery', 12, 2)->default(0);
            $table->decimal('net', 12, 2);
            $table->timestamps();

            $table->unique(['run_id', 'staff_id']);
        });
    }
};
