<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The built-in expense number format became shorter (no branch code). A clinic that
 * has already issued numbers keeps the format it was using, so its numbers
 * do not change style in the middle of a month; it can switch on the
 * settings screen, where the count carries on. A new clinic, with no numbers
 * yet, gets the short formats.
 */
return new class extends Migration
{
    protected $connection = 'clinic';

    /** @var array<string, string> the formats that were built in before */
    private const PREVIOUS = [
        'expense' => 'EXP-{branch}-{yymm}-{00001}',
    ];

    public function up(): void
    {
        $db = DB::connection('clinic');

        foreach (self::PREVIOUS as $type => $pattern) {
            $alreadySet = $db->table('settings')->where('group', 'numbering')->where('key', $type)->exists();
            $inUse = $db->table('counters')->where('counter_key', $type)->exists();

            if (! $alreadySet && $inUse) {
                $db->table('settings')->insert([
                    'group' => 'numbering',
                    'key' => $type,
                    'value' => json_encode($pattern),
                    'is_encrypted' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: the stored formats are the ones the clinic was using.
    }
};
