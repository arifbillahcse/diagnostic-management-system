<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Tenancy\ClinicContext;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\RememberClinicJob;
use Tests\TestCase;

final class QueueTest extends TestCase
{
    public function test_a_queued_job_runs_against_the_clinic_it_was_created_for(): void
    {
        config(['queue.default' => 'database']);
        RememberClinicJob::$seen = [];

        $alpha = $this->createClinic('alpha');
        $beta = $this->createClinic('beta');
        $manager = app(ClinicManager::class);

        $manager->activate($beta);
        RememberClinicJob::dispatch();
        $manager->activate($alpha);
        RememberClinicJob::dispatch();

        // The worker starts with no clinic, like a real queue worker.
        $manager->deactivate();
        $this->assertSame(2, DB::connection('control')->table('jobs')->count());

        Artisan::call('queue:work', ['--once' => true]);
        Artisan::call('queue:work', ['--once' => true]);

        $this->assertSame(['owner@beta.test', 'owner@alpha.test'], RememberClinicJob::$seen);
        $this->assertFalse(app(ClinicContext::class)->has(), 'the worker leaves the clinic after each job');
        $this->assertSame(0, DB::connection('control')->table('failed_jobs')->count());
    }
}
