<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Signatories are now kept by the core (table "signatories") so imaging
| reports can use them too. Copy this module's rows across, then drop its
| own table.
*/
return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        $db = DB::connection('clinic');

        if (! Schema::connection('clinic')->hasTable('rep_signatories')) {
            return;
        }

        $db->transaction(function () use ($db): void {
            foreach ($db->table('rep_signatories')->orderBy('id')->get() as $row) {
                $db->table('signatories')->updateOrInsert(['user_id' => $row->user_id], [
                    'display_name' => $row->display_name,
                    'qualifications' => $row->qualifications,
                    'signature_path' => $row->signature_path,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        });

        Schema::connection('clinic')->drop('rep_signatories');
    }
};
