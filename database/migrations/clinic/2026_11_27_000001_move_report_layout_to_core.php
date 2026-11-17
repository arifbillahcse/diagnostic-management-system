<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Report page set-up and signatories become shared by every kind of report
| (lab, imaging). The layout settings move to the "documents" group; the
| lab reports module copies its signatories into the new table itself.
*/
return new class extends Migration
{
    protected $connection = 'clinic';

    private const LAYOUT_KEYS = ['letterhead', 'top_margin_mm', 'bottom_margin_mm', 'footer_text', 'header_image_path', 'footer_image_path', 'watermark'];

    public function up(): void
    {
        Schema::connection('clinic')->create('signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('display_name');
            $table->text('qualifications')->nullable(); // one line each, printed under the name
            $table->string('signature_path')->nullable();
            $table->timestamps();
        });

        DB::connection('clinic')->table('settings')
            ->where('group', 'reports')->whereIn('key', self::LAYOUT_KEYS)
            ->update(['group' => 'documents']);
    }

    public function down(): void
    {
        DB::connection('clinic')->table('settings')
            ->where('group', 'documents')->whereIn('key', self::LAYOUT_KEYS)
            ->update(['group' => 'reports']);

        Schema::connection('clinic')->dropIfExists('signatories');
    }
};
