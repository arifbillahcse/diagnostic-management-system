<?php

declare(strict_types=1);

namespace Modules\Demo\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Modules\ModuleManager;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Demo\Filament\DemoNotesWidget;
use Modules\Demo\Filament\ManageDemoNotes;
use Modules\Demo\Models\DemoNote;
use Tests\TestCase;

final class DemoModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_it_is_off_for_a_new_clinic(): void
    {
        $this->useClinic('alpha');

        $this->assertFalse(Schema::connection('clinic')->hasTable('demo_notes'));

        $this->signIn($this->owner())->get('http://alpha.test/')->assertOk()->assertDontSee('Demo notes');
        $this->signIn($this->owner())->get('http://alpha.test/demo-notes')->assertForbidden();
        $this->assertFalse(DemoNotesWidget::canView());
    }

    public function test_switching_it_on_adds_its_screen_menu_item_and_widget(): void
    {
        $this->useClinic('alpha');
        app(ModuleManager::class)->enable('demo');
        $owner = $this->owner();

        $this->signIn($owner)->get('http://alpha.test/')->assertOk()->assertSee('Demo notes');
        $this->signIn($owner)->get('http://alpha.test/demo-notes')->assertOk();

        $this->actingAs($owner);
        $this->assertTrue(DemoNotesWidget::canView());
    }

    public function test_its_screen_needs_its_permission(): void
    {
        $this->useClinic('alpha');
        app(ModuleManager::class)->enable('demo');

        // The menu is checked first: Filament builds it once per PHP process,
        // and a real request never shares a process with another user.
        $receptionist = $this->userWithRoles('receptionist');
        $this->signIn($receptionist)->get('http://alpha.test/')->assertOk()->assertDontSee('Demo notes');
        $this->signIn($receptionist)->get('http://alpha.test/demo-notes')->assertForbidden();

        $this->signIn($this->userWithRoles('admin'))->get('http://alpha.test/demo-notes')->assertOk();
    }

    public function test_notes_can_be_added_and_are_audited(): void
    {
        $this->useClinic('alpha');
        app(ModuleManager::class)->enable('demo');
        $this->actingAs($this->owner());

        Livewire::test(ManageDemoNotes::class)
            ->callAction('create', ['title' => 'First note', 'body' => 'Hello'])
            ->assertHasNoFormErrors();

        $note = DemoNote::sole();
        $this->assertSame('First note', $note->title);
        $this->assertSame($note->id, AuditLog::where('action', 'demonote.created')->sole()->entity_id);
    }

    public function test_switching_it_off_hides_it_and_keeps_the_notes(): void
    {
        $this->useClinic('alpha');
        $modules = app(ModuleManager::class);
        $modules->enable('demo');
        DemoNote::create(['title' => 'Keep me']);

        $modules->disable('demo');

        $this->signIn($this->owner())->get('http://alpha.test/demo-notes')->assertForbidden();
        $this->signIn($this->owner())->get('http://alpha.test/')->assertDontSee('Demo notes');
        $this->assertSame(1, DemoNote::count());

        $modules->enable('demo');

        $this->signIn($this->owner())->get('http://alpha.test/demo-notes')->assertOk()->assertSee('Keep me');
    }
}
