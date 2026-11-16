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
        // How a person who verifies results is named on a printed report.
        Schema::connection('clinic')->create('rep_signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('display_name');
            $table->text('qualifications')->nullable(); // one line each, printed under the name
            $table->string('signature_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('clinic')->dropIfExists('rep_signatories');
    }
};
