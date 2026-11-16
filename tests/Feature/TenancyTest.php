<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicDatabase;
use App\Core\Tenancy\ClinicManager;
use App\Core\Tenancy\ClinicProvisioner;
use App\Core\Tenancy\ClinicStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

final class TenancyTest extends TestCase
{
    public function test_a_new_clinic_gets_its_own_database_with_starting_data(): void
    {
        $clinic = $this->useClinic('alpha');

        $this->assertSame(ClinicStatus::Trial, $clinic->status);
        $this->assertSame('dmstest_clinic_'.$clinic->id, $clinic->db_name);
        $this->assertSame(['alpha.test'], $clinic->domains->pluck('domain')->all());

        $this->assertSame(1, Branch::count());
        $this->assertSame('MAIN', Branch::first()->code);
        $this->assertEqualsCanonicalizing(
            ['owner', 'admin', 'receptionist', 'lab_technician', 'verifier', 'accountant'],
            Role::pluck('key')->all(),
        );

        $owner = $this->owner();
        $this->assertSame('owner@alpha.test', $owner->email);
        $this->assertNotSame(self::PASSWORD, $owner->password);
        $this->assertTrue(Role::where('key', 'owner')->first()->is_system);
    }

    public function test_clinics_cannot_see_each_others_data(): void
    {
        $alpha = $this->createClinic('alpha');
        $beta = $this->createClinic('beta');
        $manager = app(ClinicManager::class);

        $manager->activate($alpha);
        User::create(['name' => 'Only In Alpha', 'email' => 'only@alpha.test', 'password' => self::PASSWORD]);
        $this->assertSame(2, User::count());

        $manager->activate($beta);
        $this->assertSame(1, User::count());
        $this->assertFalse(User::where('email', 'only@alpha.test')->exists());
        $this->assertSame('owner@beta.test', User::first()->email);

        $manager->activate($alpha);
        $this->assertTrue(User::where('email', 'only@alpha.test')->exists());
    }

    public function test_clinic_queries_fail_when_no_clinic_is_resolved(): void
    {
        $this->createClinic('alpha');
        app(ClinicManager::class)->deactivate();

        $this->expectException(Throwable::class);
        User::count();
    }

    public function test_run_restores_the_previously_active_clinic(): void
    {
        $alpha = $this->useClinic('alpha');
        $beta = $this->createClinic('beta');
        app(ClinicManager::class)->activate($alpha);

        $seen = app(ClinicManager::class)->run($beta, fn () => User::first()->email);

        $this->assertSame('owner@beta.test', $seen);
        $this->assertSame('owner@alpha.test', User::first()->email);
    }

    public function test_a_domain_can_belong_to_only_one_clinic(): void
    {
        $this->createClinic('alpha');

        $this->expectException(InvalidArgumentException::class);
        $this->createClinic('alpha');
    }

    public function test_an_unknown_plan_is_refused_before_anything_is_created(): void
    {
        try {
            app(ClinicProvisioner::class)->provision('Broken', 'broken.test', 'X', 'x@broken.test', self::PASSWORD, 'no-such-plan');
            $this->fail('Provisioning should have failed.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, Clinic::count());
    }

    public function test_a_setup_that_fails_midway_removes_the_clinic_and_its_database(): void
    {
        $this->createClinic('alpha');

        // The BrokenSet fixture holds a default-on module whose migration
        // throws, so setup fails after the database has been created.
        $this->useModuleFixtures('BrokenSet');

        try {
            $this->createClinic('beta');
            $this->fail('Provisioning should have failed.');
        } catch (Throwable) {
            // expected
        }

        $this->assertSame(['alpha.test'], DB::connection('control')->table('clinic_domains')->pluck('domain')->all());
        $this->assertSame(1, Clinic::count());

        if ($this->onMySql()) {
            $this->assertEmpty(DB::connection('control')->select("SHOW DATABASES LIKE 'dmstest\\_clinic\\_2'"));
        } else {
            $this->assertFileDoesNotExist(app(ClinicDatabase::class)->sqlitePath(new Clinic(['db_name' => 'dmstest_clinic_2'])));
        }

        $this->assertSame(1, DB::connection('control')->table('provisioning_log')->where('status', 'failed')->count());
    }

    public function test_the_migrate_command_processes_every_clinic(): void
    {
        $this->createClinic('alpha');
        $this->createClinic('beta');

        $this->artisan('clinic:migrate')->expectsOutputToContain('2 clinic(s) processed, 0 failed.')->assertSuccessful();
    }

    public function test_the_create_command_validates_its_input(): void
    {
        $this->artisan('clinic:create', [
            'name' => 'Gamma Lab',
            'domain' => 'gamma.test',
            '--owner-name' => 'G Owner',
            '--owner-email' => 'not-an-email',
            '--owner-password' => 'short',
        ])->assertFailed();

        $this->assertSame(0, Clinic::count());

        $this->artisan('clinic:create', [
            'name' => 'Gamma Lab',
            'domain' => 'gamma.test',
            '--owner-name' => 'G Owner',
            '--owner-email' => 'owner@gamma.test',
            '--owner-password' => self::PASSWORD,
        ])->assertSuccessful();

        $this->assertSame(1, Clinic::count());
    }

    public function test_a_clinic_can_be_set_up_in_a_database_that_already_exists(): void
    {
        // Shared hosting: the database is made in the hosting panel.
        $this->makeEmptyDatabase('dmstest_clinic_hosted');

        $this->artisan('clinic:create', [
            'name' => 'Hosted Diagnostics',
            'domain' => 'hosted.test',
            '--owner-name' => 'Hosted Owner',
            '--owner-email' => 'owner@hosted.test',
            '--owner-password' => self::PASSWORD,
            '--database' => 'dmstest_clinic_hosted',
        ])->expectsOutputToContain('Database: dmstest_clinic_hosted')->assertSuccessful();

        $clinic = Clinic::sole();

        $this->assertSame('dmstest_clinic_hosted', $clinic->db_name);
        $this->assertNull($clinic->db_username);
        $this->assertSame(['hosted.test'], $clinic->domains->pluck('domain')->all());

        app(ClinicManager::class)->run($clinic, function (): void {
            $this->assertSame('owner@hosted.test', User::sole()->email);
            $this->assertTrue(Role::where('key', 'owner')->exists());
        });
    }

    public function test_an_existing_database_that_holds_tables_is_refused_and_left_alone(): void
    {
        $this->createClinic('alpha');
        $taken = Clinic::sole()->db_name;

        // The same database twice.
        try {
            $this->provisionInto($taken);
            $this->fail('A database that belongs to a clinic was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already belongs to a clinic', $e->getMessage());
        }

        // A database with somebody else's tables in it.
        $this->makeEmptyDatabase('dmstest_clinic_used');
        $this->inDatabase('dmstest_clinic_used', fn () => Schema::connection('clinic')->create('keep_me', fn ($table) => $table->id()));

        try {
            $this->provisionInto('dmstest_clinic_used');
            $this->fail('A database that is not empty was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('is not empty', $e->getMessage());
        }

        $this->assertSame(1, Clinic::count());
        $this->assertSame(
            ['keep_me'],
            $this->inDatabase('dmstest_clinic_used', fn () => Schema::connection('clinic')->getTableListing(Schema::connection('clinic')->getCurrentSchemaName(), schemaQualified: false)),
        );
    }

    public function test_a_database_that_does_not_exist_is_refused(): void
    {
        try {
            $this->provisionInto('dmstest_clinic_missing');
            $this->fail('A missing database was accepted.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, Clinic::count());
        $this->assertSame(0, DB::connection('control')->table('clinic_domains')->count());
    }

    public function test_a_failed_setup_in_an_existing_database_empties_it_but_keeps_it(): void
    {
        $this->makeEmptyDatabase('dmstest_clinic_hosted');
        $this->useModuleFixtures('BrokenSet');

        try {
            $this->provisionInto('dmstest_clinic_hosted');
            $this->fail('Provisioning should have failed.');
        } catch (Throwable) {
            // expected
        }

        $this->assertSame(0, Clinic::count());
        $this->assertSame([], $this->inDatabase('dmstest_clinic_hosted', fn () => Schema::connection('clinic')->getTableListing(Schema::connection('clinic')->getCurrentSchemaName(), schemaQualified: false)));
    }

    public function test_database_credentials_need_a_database_and_names_are_checked(): void
    {
        $this->artisan('clinic:create', [
            'name' => 'Hosted Diagnostics',
            'domain' => 'hosted.test',
            '--owner-name' => 'Hosted Owner',
            '--owner-email' => 'owner@hosted.test',
            '--owner-password' => self::PASSWORD,
            '--db-username' => 'someone',
            '--db-password' => 'secret',
        ])->assertFailed();

        foreach (['bad`name', 'Bad-Name', str_repeat('a', 65)] as $name) {
            try {
                $this->provisionInto($name);
                $this->fail("The database name [{$name}] was accepted.");
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, Clinic::count());
    }

    private function provisionInto(string $database): Clinic
    {
        return app(ClinicProvisioner::class)->provision(
            name: 'Hosted Diagnostics',
            domain: 'hosted.test',
            ownerName: 'Hosted Owner',
            ownerEmail: 'owner@hosted.test',
            ownerPassword: self::PASSWORD,
            database: $database,
        );
    }

    private function makeEmptyDatabase(string $name): void
    {
        if ($this->onMySql()) {
            DB::connection('control')->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            return;
        }

        $path = app(ClinicDatabase::class)->sqlitePath(new Clinic(['db_name' => $name]));
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');
    }

    private function inDatabase(string $name, callable $work): mixed
    {
        return app(ClinicManager::class)->run(new Clinic(['db_name' => $name]), $work);
    }
}
