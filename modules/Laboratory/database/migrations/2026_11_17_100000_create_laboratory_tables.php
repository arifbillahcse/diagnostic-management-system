<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratory tables. See docs/schema.md, section 5.
 */
return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        $schema = Schema::connection('clinic');

        $schema->create('lab_sample_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Blood (EDTA), Serum, Urine
            $table->string('container')->nullable(); // Lavender top tube
            $table->unsignedTinyInteger('label_copies')->default(1);
            $table->timestamps();
        });

        // The laboratory's details for a billable service of type "lab_test".
        $schema->create('lab_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained('fd_services')->restrictOnDelete();
            $table->foreignId('sample_type_id')->constrained('lab_sample_types')->restrictOnDelete();
            $table->string('department')->nullable(); // Haematology, Biochemistry
            $table->unsignedSmallInteger('turnaround_hours')->nullable();
            $table->string('method')->nullable();
            $table->text('report_notes')->nullable();
            $table->timestamps();
        });

        // One measured value, for example haemoglobin.
        $schema->create('lab_analytes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('result_type', 20); // numeric, option, text, long_text
            $table->string('unit', 40)->nullable();
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->json('options')->nullable();          // allowed answers of an "option" analyte
            $table->json('abnormal_options')->nullable(); // which of them are flagged abnormal
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });

        $schema->create('lab_test_analytes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_test_id')->constrained('lab_tests')->cascadeOnDelete();
            $table->foreignId('analyte_id')->constrained('lab_analytes')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('group_label')->nullable(); // heading on the report, e.g. "Differential count"
            $table->boolean('is_required')->default(true); // must be filled before results are submitted
            $table->unique(['lab_test_id', 'analyte_id']);
        });

        $schema->create('lab_reference_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analyte_id')->constrained('lab_analytes')->cascadeOnDelete();
            $table->string('sex', 10)->default('any'); // any, male, female
            $table->unsignedInteger('age_min_days')->default(0);
            $table->unsignedInteger('age_max_days')->default(54750); // 150 years
            $table->decimal('low', 18, 6)->nullable();
            $table->decimal('high', 18, 6)->nullable();
            $table->decimal('critical_low', 18, 6)->nullable();
            $table->decimal('critical_high', 18, 6)->nullable();
            $table->string('text_range')->nullable(); // printed instead of "low - high" when set
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['analyte_id', 'sex', 'age_min_days']);
        });

        // One tube or container. The accession number is the barcode.
        $schema->create('lab_samples', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('accession_no', 30)->unique();
            $table->foreignId('order_id')->constrained('fd_orders')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('sample_type_id')->constrained('lab_sample_types')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 20);
            $table->foreignId('collected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'created_at']);
            $table->index('order_id');
        });

        $schema->create('lab_sample_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_id')->constrained('lab_samples')->cascadeOnDelete();
            $table->string('event', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->string('note')->nullable();

            $table->index(['sample_id', 'occurred_at']);
        });

        // One test to be performed. Carries the result state machine, so
        // the laboratory never adds columns to the front desk's order items.
        $schema->create('lab_work_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_item_id')->unique()->constrained('fd_order_items')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('fd_orders')->restrictOnDelete();
            $table->foreignId('lab_test_id')->constrained('lab_tests')->restrictOnDelete();
            $table->foreignId('sample_id')->nullable()->constrained('lab_samples')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('test_name');
            $table->string('department')->nullable();
            $table->string('status', 20);
            $table->foreignId('entered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('entered_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('released_at')->nullable();
            // Number of the verified report: 1 for the first, 2 after one amendment.
            $table->unsignedSmallInteger('version')->default(0);
            // Raised on every save, so two people editing at once cannot
            // silently overwrite each other.
            $table->unsignedInteger('lock_version')->default(0);
            $table->string('pending_reason')->nullable(); // why it was sent back or is being amended
            $table->timestamps();

            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['patient_id', 'released_at']);
            $table->index('order_id');
            $table->index('sample_id');
        });

        // Current values. Units and reference ranges are copied here when a
        // value is entered, so a later catalog edit cannot change a report.
        $schema->create('lab_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained('lab_work_items')->restrictOnDelete();
            $table->foreignId('analyte_id')->constrained('lab_analytes')->restrictOnDelete();
            $table->string('analyte_name');
            $table->string('result_type', 20);
            $table->string('group_label')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->decimal('value_numeric', 18, 6)->nullable();
            $table->text('value_text')->nullable();
            $table->string('unit', 40)->nullable();
            $table->decimal('ref_low', 18, 6)->nullable();
            $table->decimal('ref_high', 18, 6)->nullable();
            $table->string('ref_text')->nullable();
            $table->string('flag', 20)->default('none');
            $table->string('comment')->nullable();
            $table->timestamps();

            $table->unique(['work_item_id', 'analyte_id']);
        });

        // Written at every verification. Never changed.
        $schema->create('lab_result_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained('lab_work_items')->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('snapshot');
            $table->string('reason')->nullable(); // set for amendments
            $table->foreignId('entered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');

            $table->unique(['work_item_id', 'version']);
        });
    }

    public function down(): void
    {
        foreach ([
            'lab_result_versions', 'lab_results', 'lab_work_items', 'lab_sample_events', 'lab_samples',
            'lab_reference_ranges', 'lab_test_analytes', 'lab_analytes', 'lab_tests', 'lab_sample_types',
        ] as $table) {
            Schema::connection('clinic')->dropIfExists($table);
        }
    }
};
