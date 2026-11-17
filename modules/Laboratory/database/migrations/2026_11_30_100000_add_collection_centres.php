<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Collection centres: a branch that takes samples and sends them to another
| branch's laboratory to be tested.
*/
return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        $schema = Schema::connection('clinic');

        // "Samples taken at this branch are tested at that branch."
        $schema->create('lab_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained('branches')->restrictOnDelete();
            $table->foreignId('lab_branch_id')->constrained('branches')->restrictOnDelete();
            $table->timestamps();
        });

        // A box of samples on its way from a collection centre to a laboratory.
        $schema->create('lab_transfers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('transfer_no', 40)->unique();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 20);                    // in_transit, received
            $table->string('note')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['to_branch_id', 'status']);
        });

        // Where each sample and test is tested. Until now: where it was ordered.
        $schema->table('lab_samples', function (Blueprint $table) {
            $table->foreignId('lab_branch_id')->nullable()->after('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained('lab_transfers')->restrictOnDelete();
            $table->index(['lab_branch_id', 'status']);
        });

        $schema->table('lab_work_items', function (Blueprint $table) {
            $table->foreignId('lab_branch_id')->nullable()->after('branch_id')->constrained('branches')->restrictOnDelete();
            $table->index(['lab_branch_id', 'status']);
        });

        DB::connection('clinic')->table('lab_samples')->update(['lab_branch_id' => DB::raw('branch_id')]);
        DB::connection('clinic')->table('lab_work_items')->update(['lab_branch_id' => DB::raw('branch_id')]);
    }
};
