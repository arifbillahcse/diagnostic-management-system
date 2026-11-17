<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit\AuditLog;
use App\Core\Modules\ModuleManager;
use App\Core\Tenancy\PendingUpdates;
use App\Filament\Pages\PortalUpdates;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** TEMPORARY, with the feature itself: remove both after the trial. */
final class PortalUpdatesTest extends TestCase
{
    private const BASE = 'http://alpha.test';

    private string $migration = '';

    /** The migration's name, without ".php". */
    private string $name = '';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['dms.portal_updates.enabled' => true]);
        $this->useModuleFixtures();
        $this->useClinic('alpha');
        app(ModuleManager::class)->enable('alpha');
        $this->actingAs($this->owner());
    }

    protected function tearDown(): void
    {
        if ($this->migration !== '') {
            File::delete($this->migration);
        }

        parent::tearDown();
    }

    /** Put a new migration into the fixture module, as a code update would. */
    private function newMigration(string $body): void
    {
        // A new name every time: Laravel remembers migration files by path.
        $this->name = '2026_02_01_000001_add_alpha_note_'.bin2hex(random_bytes(3));
        $this->migration = base_path("tests/Fixtures/ModuleSet/Alpha/database/migrations/{$this->name}.php");
        File::put($this->migration, <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected \$connection = 'clinic';

    public function up(): void
    {
        {$body}
    }
};
PHP);
    }

    private const ADD_NOTE = "Schema::connection('clinic')->table('alpha_things', fn (Blueprint \$t) => \$t->string('note')->nullable());";

    public function test_the_page_is_off_unless_the_switch_is_on(): void
    {
        config(['dms.portal_updates.enabled' => false]);

        Livewire::test(PortalUpdates::class)->assertForbidden();
        $this->signIn($this->owner())->get(self::BASE.'/database-updates')->assertForbidden();
        $this->assertFalse(PortalUpdates::canAccess());
    }

    public function test_only_the_owner_may_use_it(): void
    {
        $this->assertTrue(PortalUpdates::canAccess());

        foreach (['admin', 'receptionist', 'verifier'] as $role) {
            $this->actingAs($this->userWithRoles($role));
            $this->assertFalse(PortalUpdates::canAccess(), "{$role} must not see it");
            Livewire::test(PortalUpdates::class)->assertForbidden();
        }

        $this->actingAs($this->userWithRoles('admin'));
        $this->expectException(HttpException::class);
        app(PendingUpdates::class)->apply();
    }

    public function test_an_up_to_date_database_says_so(): void
    {
        $this->assertSame([], app(PendingUpdates::class)->clinic());
        $this->assertSame([], app(PendingUpdates::class)->control());
        $this->assertNull(PortalUpdates::getNavigationBadge());

        Livewire::test(PortalUpdates::class)->assertSee('Everything is up to date')->assertActionHidden('apply');
    }

    public function test_a_waiting_update_is_listed_then_applied_and_recorded(): void
    {
        $this->newMigration(self::ADD_NOTE);

        $this->assertSame([$this->name], app(PendingUpdates::class)->clinic());
        $this->assertSame('1', PortalUpdates::getNavigationBadge());
        $this->assertFalse(Schema::connection('clinic')->hasColumn('alpha_things', 'note'));

        Livewire::test(PortalUpdates::class)
            ->assertSee('Add alpha note')
            ->assertActionVisible('apply')
            ->callAction('apply')
            ->assertNotified('Updates applied');

        $this->assertTrue(Schema::connection('clinic')->hasColumn('alpha_things', 'note'));
        $this->assertSame([], app(PendingUpdates::class)->clinic());
        $this->assertNull(PortalUpdates::getNavigationBadge());

        $entry = AuditLog::where('action', 'platform.updates_applied')->sole();
        $this->assertSame([$this->name], $entry->after['clinic']);

        // Pressing it again changes nothing.
        $this->assertSame(['control' => [], 'clinic' => []], app(PendingUpdates::class)->apply());
    }

    public function test_an_update_for_the_platform_database_is_found_and_applied(): void
    {
        $dir = sys_get_temp_dir().'/portal-updates-'.uniqid();
        File::ensureDirectoryExists($dir);
        File::put($dir.'/2026_03_01_000001_create_portal_probe.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->create('portal_probe', fn (Blueprint $t) => $t->id());
    }
};
PHP);
        app('migrator')->path($dir);

        try {
            $this->assertSame(['2026_03_01_000001_create_portal_probe'], app(PendingUpdates::class)->control());

            $done = app(PendingUpdates::class)->apply();

            $this->assertSame(['2026_03_01_000001_create_portal_probe'], $done['control']);
            $this->assertTrue(Schema::connection('control')->hasTable('portal_probe'));
            $this->assertSame([], app(PendingUpdates::class)->control());
        } finally {
            Schema::connection('control')->dropIfExists('portal_probe');
            File::deleteDirectory($dir);
        }
    }

    public function test_a_failing_update_is_reported_and_stays_pending(): void
    {
        $this->newMigration("throw new RuntimeException('column cannot be added');");

        Livewire::test(PortalUpdates::class)
            ->callAction('apply')
            ->assertNotified('The update did not finish');

        $this->assertSame([$this->name], app(PendingUpdates::class)->clinic());
        $this->assertFalse(AuditLog::where('action', 'platform.updates_applied')->exists());
    }

    public function test_two_updates_cannot_run_at_once(): void
    {
        $lock = Cache::lock('portal-updates', 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Another update is already running');
            app(PendingUpdates::class)->apply();
        } finally {
            $lock->release();
        }
    }
}
