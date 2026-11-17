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

        // Ready-made report text per imaging service, for example "Whole abdomen: normal".
        $schema->create('img_templates', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('service_id')->constrained('fd_services')->restrictOnDelete();
            $table->string('name');
            $table->string('sex', 10)->default('any');      // any, male, female
            $table->mediumText('findings');                 // cleaned HTML
            $table->mediumText('impression');
            $table->text('advice')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['service_id', 'name']);
        });

        // Short sentences added to the findings with one click.
        $schema->create('img_phrases', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100)->unique();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // One imaging study per billed imaging test. The draft lives here
        // until it is signed; signed text goes to img_report_versions.
        $schema->create('img_studies', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_item_id')->unique()->constrained('fd_order_items')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('fd_orders')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('fd_services')->restrictOnDelete();
            $table->string('study_name');
            $table->string('status', 20);                   // pending, draft, final, amending, cancelled
            $table->unsignedSmallInteger('version')->default(0);
            $table->mediumText('draft_findings')->nullable();
            $table->mediumText('draft_impression')->nullable();
            $table->text('draft_advice')->nullable();
            $table->foreignId('draft_template_id')->nullable()->constrained('img_templates')->nullOnDelete();
            $table->foreignId('draft_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('amend_reason')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['status', 'branch_id']);
            $table->index('order_id');
        });

        // Every signed report, never changed: an amendment is a new version.
        $schema->create('img_report_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_id')->constrained('img_studies')->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->mediumText('findings');
            $table->mediumText('impression');
            $table->text('advice')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('img_templates')->nullOnDelete();
            $table->foreignId('written_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('signed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('signed_at');
            $table->string('amend_reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['study_id', 'version']);
        });
    }
};
