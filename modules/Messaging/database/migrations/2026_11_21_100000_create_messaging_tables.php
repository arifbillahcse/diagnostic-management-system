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

        // Every message the clinic sent or tried to send.
        $schema->create('msg_messages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('channel', 10);            // sms, email
            $table->string('recipient', 190);         // phone (8801...) or email address
            $table->string('template_key', 40);
            $table->string('subject')->nullable();    // email only
            $table->text('body');
            $table->unsignedSmallInteger('segments')->default(1); // SMS parts, for the bill
            $table->string('status', 10);             // queued, sent, failed
            $table->string('provider', 30)->nullable();
            $table->string('provider_ref', 190)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            // Stops the same event sending the same message twice.
            $table->string('dedupe_key', 120)->nullable()->unique();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('fd_orders')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['order_id', 'template_key']);
            $table->index('recipient');
        });

        // The clinic's own wording. A missing row means the built-in text.
        $schema->create('msg_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 40);
            $table->string('channel', 10);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['template_key', 'channel']);
        });

        // Links that let a patient download their report without signing in.
        // Only a hash of the secret part is stored.
        $schema->create('msg_report_links', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('order_id')->constrained('fd_orders')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('opened_count')->default(0);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });

        // Numbers and addresses that asked not to be contacted.
        $schema->create('msg_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->string('recipient', 190)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['msg_opt_outs', 'msg_report_links', 'msg_templates', 'msg_messages'] as $table) {
            Schema::connection('clinic')->dropIfExists($table);
        }
    }
};
