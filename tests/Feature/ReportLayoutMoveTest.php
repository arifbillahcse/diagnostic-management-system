<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Documents\PageLayout;
use App\Core\Settings\Settings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Existing clinics keep their report layout and signatories when both move
 * from the lab reports module to the core.
 */
final class ReportLayoutMoveTest extends TestCase
{
    public function test_old_layout_settings_and_signatories_are_carried_over(): void
    {
        $this->useClinic('alpha');
        $owner = $this->owner();
        $db = DB::connection('clinic');
        $schema = Schema::connection('clinic');

        // The state of a clinic before the move.
        $schema->drop('signatories');
        $schema->create('rep_signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->string('display_name');
            $table->text('qualifications')->nullable();
            $table->string('signature_path')->nullable();
            $table->timestamps();
        });
        $db->table('rep_signatories')->insert(['user_id' => $owner->id, 'display_name' => 'Dr. Nasrin Sultana', 'qualifications' => 'MBBS', 'signature_path' => 'clinics/1/signatures/a.png', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('settings')->where('group', 'documents')->delete();
        $db->table('settings')->insert([
            ['group' => 'reports', 'key' => 'letterhead', 'value' => '"preprinted"', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()],
            ['group' => 'reports', 'key' => 'top_margin_mm', 'value' => '55', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()],
            ['group' => 'reports', 'key' => 'block_print_when_due', 'value' => 'true', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        (require base_path('database/migrations/clinic/2026_11_27_000001_move_report_layout_to_core.php'))->up();
        (require base_path('modules/Reports/database/migrations/2026_11_27_100000_move_signatories_to_core.php'))->up();

        $this->assertFalse($schema->hasTable('rep_signatories'));
        $row = $db->table('signatories')->sole();
        $this->assertSame('Dr. Nasrin Sultana', $row->display_name);
        $this->assertSame('clinics/1/signatures/a.png', $row->signature_path);

        $settings = new Settings;
        $this->assertSame('preprinted', $settings->get(PageLayout::SETTING_LETTERHEAD));
        $this->assertSame(55, $settings->get(PageLayout::SETTING_TOP_MARGIN));
        $this->assertTrue($settings->get('reports.block_print_when_due'), 'The lab-only setting stays with the lab reports module');
    }
}
