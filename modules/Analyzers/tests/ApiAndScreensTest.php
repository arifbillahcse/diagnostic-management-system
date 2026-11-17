<?php

declare(strict_types=1);

namespace Modules\Analyzers\Tests;

use App\Core\Tenancy\ClinicContext;
use Livewire\Livewire;
use Modules\Analyzers\Actions\ManageMachines;
use Modules\Analyzers\Filament\ListMachines;
use Modules\Analyzers\Filament\ManageMappings;
use Modules\Analyzers\Filament\ViewMessage;
use Modules\Analyzers\Models\Machine;
use Modules\Analyzers\Models\Message;
use Modules\Laboratory\Models\Result;

class ApiAndScreensTest extends AnalyzerTestCase
{
    private function send(string $raw, ?string $key = null)
    {
        return $this->withToken($key ?? $this->key)->postJson(self::BASE.'/api/analyzers/messages', ['raw' => $raw]);
    }

    public function test_the_bridge_posts_a_message_with_the_machine_key(): void
    {
        $order = $this->cbcInLab();
        auth()->logout();

        $this->send($this->fixture('astm-cbc.txt', $this->sampleNo($order)))
            ->assertStatus(202)
            ->assertJson(['status' => 'partial', 'samples' => $this->sampleNo($order), 'saved' => 2, 'not_saved' => 1])
            ->assertCookieMissing('laravel_session');

        $this->assertSame('13.5', Result::where('work_item_id', $this->work($order, $this->cbc)->id)->where('analyte_name', 'Haemoglobin')->value('value_text'));
        $this->assertNotNull($this->machine->fresh()->last_seen_at);
    }

    public function test_wrong_keys_empty_messages_and_a_switched_off_module_are_refused(): void
    {
        auth()->logout();
        $this->send('H|\^&', 'anz_'.str_repeat('x', 40))->assertStatus(401);
        $this->send('H|\^&', 'not-a-key')->assertStatus(401);
        $this->send('   ')->assertStatus(422);

        $this->actingAs($this->ownerUser);
        $new = app(ManageMachines::class)->replaceKey($this->machine);
        auth()->logout();
        $this->send('H|\^&')->assertStatus(401);
        $this->send('H|\^&', $new)->assertStatus(202);

        $this->machine->forceFill(['is_active' => false])->save();
        $this->send('H|\^&|other', $new)->assertStatus(401);

        $this->disableModule('analyzers');
        $this->send('H|\^&', $new)->assertNotFound();
        $this->assertSame(1, Message::count());
    }

    public function test_keys_are_stored_only_as_hashes(): void
    {
        $this->assertStringStartsWith('anz_', $this->key);
        $this->assertSame(hash('sha256', $this->key), $this->machine->fresh()->token_hash);
        $this->assertSame(substr($this->key, -4), $this->machine->token_hint);
        $this->assertArrayNotHasKey('token_hash', $this->machine->toArray());
    }

    public function test_machines_codes_and_the_inbox_on_screen(): void
    {
        $this->signIn($this->ownerUser)->get(self::BASE.'/lab-machines')->assertOk()->assertSee('Haematology analyzer');

        Livewire::test(ListMachines::class)
            ->callAction('add', ['name' => 'Chemistry analyzer', 'protocol' => 'hl7', 'branch_id' => $this->branch->id, 'user_id' => $this->technician->id])
            ->assertNotified('Copy this key now');
        $this->assertSame('hl7', Machine::where('name', 'Chemistry analyzer')->value('protocol'));

        Livewire::test(ListMachines::class)
            ->callAction('add', ['name' => 'Bad', 'protocol' => 'astm', 'branch_id' => $this->branch->id, 'user_id' => $this->staff('receptionist')->id])
            ->assertNotified('Not done');

        Livewire::test(ManageMappings::class)
            ->callAction('create', ['machine_id' => $this->machine->id, 'machine_code' => 'HGB', 'analyte_id' => $this->hb->id])
            ->assertHasActionErrors(['machine_code']);

        Livewire::test(ListMachines::class)
            ->callTableAction('try', $this->machine, ['raw' => "H|\\^&\nO|1|MISREAD||^^^CBC\nR|1|^^^HGB|13.5|g/dL\nL|1|N"])
            ->assertNotified('Message: Not matched');

        $message = Message::sole();
        $order = $this->cbcInLab();
        $this->signIn($this->staff('lab_technician'))->get(self::BASE."/lab-machines/inbox/{$message->public_id}")->assertOk()->assertSee('MISREAD');

        $this->actingAs($this->technician);
        Livewire::test(ViewMessage::class, ['record' => $message->public_id])
            ->callAction('match', ['sample' => $this->sampleNo($order)])
            ->assertNotified('Matched again');
        $this->assertSame('applied', $message->fresh()->status);

        $this->signIn($this->staff('lab_technician'))->get(self::BASE.'/lab-machines')->assertForbidden();
        $this->signIn($this->staff('receptionist'))->get(self::BASE.'/lab-machines/inbox')->assertForbidden();
    }

    public function test_the_simulator_replays_recorded_output(): void
    {
        $order = $this->cbcInLab();
        $file = tempnam(sys_get_temp_dir(), 'anz');
        file_put_contents($file, $this->fixture('astm-cbc.txt', $this->sampleNo($order))."\n---\n".str_replace('{SAMPLE}', '0000000000', (string) file_get_contents(__DIR__.'/fixtures/astm-cbc.txt')));

        $this->artisan('analyzers:replay', ['clinic' => app(ClinicContext::class)->id(), 'machine' => 'Haematology analyzer', 'file' => $file])
            ->expectsOutputToContain('partial, 2 value(s) saved')
            ->expectsOutputToContain('unmatched')
            ->assertSuccessful();

        unlink($file);
    }
}
