<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit\AuditLog;
use App\Core\Audit\AuditLogger;
use App\Core\Backup\ClinicBackup;
use App\Core\Branches\Branch;
use App\Core\Patients\Actions\SavePatient;
use App\Core\Tenancy\ClinicHardening;
use App\Filament\Pages\MyAccount;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

final class SafetyTest extends TestCase
{
    private const BASE = 'http://alpha.test';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_pages_carry_security_headers_and_are_never_cached(): void
    {
        $this->useClinic();

        foreach ([$this->get(self::BASE.'/login'), $this->signIn($this->owner())->get(self::BASE.'/patients')] as $response) {
            $response->assertOk()
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'same-origin');

            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }

        $this->assertNull($this->get(self::BASE.'/login')->headers->get('Strict-Transport-Security'), 'only sent over HTTPS');
        $this->assertSame('max-age=31536000', $this->get('https://alpha.test/login')->headers->get('Strict-Transport-Security'));
    }

    public function test_a_user_changes_their_own_password_and_nothing_else(): void
    {
        $this->useClinic();
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(MyAccount::class)
            ->fillForm(['password' => 'a-brand-new-password', 'passwordConfirmation' => 'a-brand-new-password', 'currentPassword' => 'not-my-password'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        Livewire::test(MyAccount::class)
            ->fillForm(['password' => 'short', 'passwordConfirmation' => 'short', 'currentPassword' => self::PASSWORD])
            ->call('save')
            ->assertHasFormErrors(['password']);

        $this->assertTrue(Hash::check(self::PASSWORD, $owner->fresh()->password));

        Livewire::test(MyAccount::class)
            ->fillForm(['password' => 'a-brand-new-password', 'passwordConfirmation' => 'a-brand-new-password', 'currentPassword' => self::PASSWORD])
            // A crafted request tries to change more than the password.
            ->set('data.name', 'Hijacked')
            ->set('data.email', 'attacker@example.test')
            ->set('data.is_active', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $owner->refresh();
        $this->assertTrue(Hash::check('a-brand-new-password', $owner->password));
        $this->assertSame(['Alpha Owner', 'owner@alpha.test', true], [$owner->name, $owner->email, $owner->is_active]);

        $entry = AuditLog::where('action', 'user.password_changed')->sole();
        $this->assertSame($owner->id, $entry->entity_id);
        $this->assertStringNotContainsString('a-brand-new-password', json_encode($entry->getAttributes()));
    }

    public function test_two_step_sign_in_is_stored_encrypted_and_asked_for_at_login(): void
    {
        $this->useClinic();
        $owner = $this->owner();
        $this->assertFalse($owner->hasTwoStepSignIn());

        $provider = AppAuthentication::make();
        $secret = $provider->generateSecret();
        $owner->saveAppAuthenticationSecret($secret);
        $owner->saveAppAuthenticationRecoveryCodes(['code-one', 'code-two']);

        $stored = DB::connection('clinic')->table('users')->where('id', $owner->id)->first();
        $this->assertStringNotContainsString($secret, $stored->app_authentication_secret);
        $this->assertStringNotContainsString('code-one', $stored->app_authentication_recovery_codes);
        $this->assertSame($secret, $owner->fresh()->getAppAuthenticationSecret());
        $this->assertArrayNotHasKey('app_authentication_secret', $owner->fresh()->toArray());
        $this->assertSame(1, AuditLog::where('action', 'user.two_step_enabled')->count());

        // The right password alone no longer signs this user in.
        Livewire::test(Login::class)
            ->fillForm(['email' => 'owner@alpha.test', 'password' => self::PASSWORD])
            ->call('authenticate');

        $this->assertGuest();

        // A user without it signs in with the password as before.
        $plain = $this->userWithRoles('admin');
        Livewire::test(Login::class)
            ->fillForm(['email' => $plain->email, 'password' => self::PASSWORD])
            ->call('authenticate');

        $this->assertAuthenticatedAs($plain);
    }

    public function test_the_database_itself_refuses_changes_to_append_only_tables(): void
    {
        if (! $this->onMySql()) {
            $this->markTestSkipped('Database triggers exist on MySQL only. Run the suite with DB_DRIVER=mysql.');
        }

        $this->useClinic();
        $this->actingAs($this->owner());
        app(AuditLogger::class)->record('test.entry');
        $db = DB::connection('clinic');

        // Straight to the database, past every rule in the application.
        foreach ([
            fn () => $db->table('audit_logs')->update(['action' => 'tampered']),
            fn () => $db->table('audit_logs')->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('MySQL should have refused.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('audit_logs is append-only', $e->getMessage());
            }
        }

        $this->assertGreaterThan(0, $db->table('audit_logs')->where('action', 'test.entry')->count());

        // Module tables are covered too, and applying it again is harmless.
        $protected = app(ClinicHardening::class)->apply();
        $this->assertContains('fd_payments', $protected);
        $this->assertContains('fd_invoice_lines', $protected);
        $this->assertContains('lab_result_versions', $protected);
        $this->assertNotContains('fin_account_entries', $protected, 'finance is not installed in this clinic');

        $this->assertSame(2, count($db->select("SHOW TRIGGERS LIKE 'fd_payments'")));

        // Inserting still works.
        app(AuditLogger::class)->record('test.after');
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'test.after')->count());
    }

    public function test_a_backup_can_be_restored_and_a_damaged_one_is_caught(): void
    {
        if (! $this->onMySql()) {
            $this->markTestSkipped('Backups use mysqldump. Run the suite with DB_DRIVER=mysql.');
        }

        $clinic = $this->useClinic();
        $this->actingAs($this->owner());
        app(SavePatient::class)->register(['name' => 'Rahim Uddin', 'sex' => 'male', 'date_of_birth' => '1990-01-01'], Branch::first());

        $dir = storage_path('framework/testing/backups-'.getmypid());
        $backup = app(ClinicBackup::class);

        try {
            foreach ([null, 'a long test passphrase'] as $passphrase) {
                config(['dms.backup.passphrase' => $passphrase]);

                $result = $backup->create($clinic->db_name, $dir, $clinic);

                $this->assertFileExists($result['file']);
                $this->assertSame($passphrase !== null, $result['encrypted']);
                $this->assertSame('0600', substr(sprintf('%o', fileperms($result['file'])), -4));

                $manifest = json_decode(file_get_contents($result['manifest']), true);
                $this->assertSame(1, $manifest['tables']['patients']);
                $this->assertSame($clinic->id, $manifest['clinic_id']);

                // The file on disk must not hold readable patient data when encrypted.
                $raw = file_get_contents($result['file']);
                $this->assertStringNotContainsString('Rahim Uddin', $raw);
                if ($passphrase === null) {
                    $this->assertStringContainsString('Rahim Uddin', gzdecode($raw));
                }

                $check = $backup->verify($result['file']);
                $this->assertTrue($check['ok'], implode(' ', $check['problems']));
                $this->assertSame($result['rows'], $check['rows']);

                // A manifest that expects a row the backup does not have.
                $manifest['tables']['patients'] = 2;
                file_put_contents($result['manifest'], json_encode($manifest));
                $bad = $backup->verify($result['file']);
                $this->assertFalse($bad['ok']);
                $this->assertStringContainsString('patients: 1 rows restored, 2 expected', $bad['problems'][0]);

                // A file changed after the backup was made.
                file_put_contents($result['file'], 'x', FILE_APPEND);
                $this->assertStringContainsString('changed or damaged', $backup->verify($result['file'])['problems'][0]);
            }

            $this->assertEmpty(DB::connection('control')->select("SHOW DATABASES LIKE 'dms\\_verify\\_%'"), 'scratch databases are removed');

            config(['dms.backup.passphrase' => null]);
            $this->artisan('clinic:backup', ['--path' => $dir, '--verify' => true])
                ->expectsOutputToContain('NOT encrypted')
                ->expectsOutputToContain('restore checked')
                ->expectsOutputToContain('2 database(s) processed, 0 failed')
                ->assertSuccessful();
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_the_doctor_command_names_what_must_be_fixed(): void
    {
        $this->artisan('platform:doctor')
            ->expectsOutputToContain('Debug mode is off')
            ->expectsOutputToContain('Backups are encrypted')
            ->assertFailed();

        config([
            'app.debug' => false, 'app.env' => 'production', 'app.url' => 'https://clinic.example.test',
            'session.secure' => true, 'queue.default' => 'database',
            'dms.backup.path' => '/var/backups/dms', 'dms.backup.passphrase' => 'secret passphrase',
        ]);

        $run = $this->artisan('platform:doctor');
        $this->onMySql() ? $run->expectsOutputToContain('All checks passed.')->assertSuccessful() : $run->assertFailed();
    }
}
