<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit\AuditLog;
use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use App\Core\Settings\Actions\UpdateSettings;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class AuditAndSettingsTest extends TestCase
{
    public function test_audit_entries_cannot_be_changed_or_deleted(): void
    {
        $this->useClinic();
        $entry = app(AuditLogger::class)->record('test.event', null, [], [], ['note' => 'original']);

        try {
            $entry->update(['action' => 'tampered']);
            $this->fail('Updating an audit entry should be refused.');
        } catch (LogicException) {
            $this->assertSame('test.event', AuditLog::find($entry->id)->action);
        }

        try {
            $entry->delete();
            $this->fail('Deleting an audit entry should be refused.');
        } catch (LogicException) {
            $this->assertTrue(AuditLog::whereKey($entry->id)->exists());
        }
    }

    public function test_the_actor_is_the_signed_in_user_or_the_system(): void
    {
        $this->useClinic();

        $system = app(AuditLogger::class)->record('test.system');
        $this->assertSame('system', $system->actor_type);
        $this->assertNull($system->actor_id);

        $owner = $this->owner();
        $this->actingAs($owner);
        $byUser = app(AuditLogger::class)->record('test.user');
        $this->assertSame('user', $byUser->actor_type);
        $this->assertSame($owner->id, $byUser->actor_id);
        $this->assertSame($owner->name, $byUser->actor_name);
    }

    public function test_secrets_and_timestamps_never_reach_the_trail(): void
    {
        $this->useClinic();

        $entry = app(AuditLogger::class)->record('test.secret', null, [], [
            'name' => 'visible',
            'password' => 'super-secret',
            'api_key' => 'sk-123',
            'updated_at' => '2026-01-01',
        ]);

        $this->assertSame(['name' => 'visible', 'password' => '[hidden]', 'api_key' => '[hidden]'], $entry->after);
    }

    public function test_simple_models_are_audited_automatically(): void
    {
        $this->useClinic();
        $this->actingAs($this->owner());

        $branch = Branch::create(['code' => 'B2', 'name' => 'Second']);
        $branch->update(['name' => 'Second branch']);
        $branch->update(['name' => 'Second branch']);

        $this->assertSame(1, AuditLog::where('action', 'branch.created')->where('entity_id', $branch->id)->count());

        $updated = AuditLog::where('action', 'branch.updated')->sole();
        $this->assertSame(['name' => 'Second'], $updated->before);
        $this->assertSame(['name' => 'Second branch'], $updated->after);
    }

    public function test_settings_are_stored_read_back_and_audited(): void
    {
        $this->useClinic();
        $this->actingAs($this->owner());
        $settings = app(Settings::class);

        $this->assertSame('Alpha Diagnostics', $settings->get('clinic.name'));
        $this->assertSame('fallback', $settings->get('clinic.missing', 'fallback'));

        app(UpdateSettings::class)->handle([
            'clinic.name' => 'Alpha Diagnostic Center',
            'clinic.phone' => '01700-000000',
        ]);

        $this->assertSame('Alpha Diagnostic Center', $settings->get('clinic.name'));
        $this->assertSame('01700-000000', $settings->get('clinic.phone'));

        $entry = AuditLog::where('action', 'settings.updated')->sole();
        $this->assertSame(['clinic.name' => 'Alpha Diagnostics', 'clinic.phone' => null], $entry->before);
    }

    public function test_encrypted_settings_are_unreadable_in_the_database_and_the_trail(): void
    {
        $this->useClinic();
        $this->actingAs($this->owner());

        app(UpdateSettings::class)->handle(['sms.api_secret' => 'top-secret-value'], encrypted: ['sms.api_secret']);

        $this->assertSame('top-secret-value', app(Settings::class)->get('sms.api_secret'));

        $stored = DB::connection('clinic')->table('settings')->where('group', 'sms')->value('value');
        $this->assertStringNotContainsString('top-secret-value', $stored);

        $entry = AuditLog::where('action', 'settings.updated')->sole();
        $this->assertStringNotContainsString('top-secret-value', json_encode($entry->getAttributes()));
    }

    public function test_settings_need_the_permission(): void
    {
        $this->useClinic();
        $this->actingAs($this->userWithRoles('receptionist'));

        $this->expectException(AuthorizationException::class);
        app(UpdateSettings::class)->handle(['clinic.name' => 'Hijacked']);
    }

    public function test_settings_do_not_leak_between_clinics(): void
    {
        $alpha = $this->useClinic('alpha');
        $this->assertSame('Alpha Diagnostics', app(Settings::class)->get('clinic.name'));

        $beta = $this->createClinic('beta');
        app(ClinicManager::class)->activate($beta);
        $this->assertSame('Beta Diagnostics', app(Settings::class)->get('clinic.name'));

        app(ClinicManager::class)->activate($alpha);
        $this->assertSame('Alpha Diagnostics', app(Settings::class)->get('clinic.name'));
    }
}
