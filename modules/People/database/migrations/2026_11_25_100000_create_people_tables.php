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

        // Everyone the clinic pays, whether or not they sign in to the system.
        $schema->create('ppl_staff', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('staff_no', 40)->unique();
            $table->string('name');
            $table->string('designation', 100);
            $table->string('phone', 30)->nullable();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->date('joined_on');
            $table->date('left_on')->nullable();
            $table->string('leave_reason')->nullable();
            // Current salary. Changed only through a salary change row below.
            $table->decimal('monthly_salary', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index(['branch_id', 'left_on']);
        });

        // Salary history. Append-only: a change is a new row.
        $schema->create('ppl_salary_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('ppl_staff')->restrictOnDelete();
            $table->decimal('monthly_salary', 12, 2);
            $table->date('effective_from');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['staff_id', 'effective_from']);
        });

        // One row per staff member per day. Changes are audited.
        $schema->create('ppl_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('ppl_staff')->restrictOnDelete();
            $table->date('date');
            $table->string('status', 20);
            $table->time('in_time')->nullable();
            $table->time('out_time')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['staff_id', 'date']);
            $table->index('date');
        });
    }
};
