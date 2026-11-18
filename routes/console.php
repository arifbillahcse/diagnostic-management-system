<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly backup of every database, checked by a trial restore. It does
// nothing until BACKUP_PATH is set.
if (filled(config('dms.backup.path'))) {
    Schedule::command('clinic:backup --verify')->dailyAt('02:30')->timezone('Asia/Dhaka');
    Schedule::command('clinic:licence-check')->dailyAt('00:10')->timezone('Asia/Dhaka');
}
