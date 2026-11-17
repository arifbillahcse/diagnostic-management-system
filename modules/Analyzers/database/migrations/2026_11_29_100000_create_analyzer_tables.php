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

        // A lab analyzer that sends results through the bridge.
        $schema->create('anz_machines', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 100)->unique();
            $table->string('model', 100)->nullable();
            $table->string('protocol', 10);                   // astm, hl7
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            // Results are saved as drafts under this lab account.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();         // sha256 of the secret key
            $table->string('token_hint', 8);                  // last characters, to recognise a key
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // "This machine calls haemoglobin HGB."
        $schema->create('anz_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('anz_machines')->restrictOnDelete();
            $table->string('machine_code', 50);
            $table->foreignId('analyte_id')->constrained('lab_analytes')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['machine_id', 'machine_code']);
        });

        // Every message as it arrived. The raw text is never changed.
        $schema->create('anz_messages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('machine_id')->constrained('anz_machines')->restrictOnDelete();
            $table->string('protocol', 10);
            $table->mediumText('raw');
            $table->char('raw_hash', 64);                     // the same message sent twice is stored once
            $table->string('status', 20);                     // applied, partial, unmatched, failed
            $table->string('sample_codes')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['machine_id', 'raw_hash']);
            $table->index(['status', 'received_at']);
        });

        // Each value in a message and what became of it.
        $schema->create('anz_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('anz_messages')->restrictOnDelete();
            $table->string('sample_code', 60)->nullable();
            $table->string('machine_code', 50);
            $table->string('value', 100);
            $table->string('unit', 30)->nullable();
            $table->string('machine_flag', 20)->nullable();
            $table->foreignId('analyte_id')->nullable()->constrained('lab_analytes')->nullOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained('lab_work_items')->nullOnDelete();
            $table->string('status', 20);                     // applied, unmapped, no_sample, not_received, no_test, already_submitted, failed
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['message_id', 'status']);
        });
    }
};
