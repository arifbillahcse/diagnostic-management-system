<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Tenancy\ClinicManager;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * Opening an "Edit" window must work on every list. A record holds money and
 * stock figures as objects, and Livewire cannot keep objects between requests:
 * a list that fills its edit window with the whole record fails with
 * "Property type not supported in Livewire". This opens every edit window
 * on real sample data.
 */
final class EditFormsTest extends TestCase
{
    public function test_every_edit_window_opens_on_sample_data(): void
    {
        $clinic = $this->createClinic('alpha');
        $this->artisan('clinic:demo-data', ['clinic' => $clinic->id, '--password' => 'sample-pass-123', '--force' => true])->assertSuccessful();

        app(ClinicManager::class)->activate($clinic);
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner());

        $opened = [];
        $failed = [];

        foreach (self::resources() as $resource) {
            $index = $resource::getPages()['index'] ?? null;

            try {
                $record = $resource::getModel()::query()->first();
            } catch (QueryException) {
                continue; // a module that is switched off has no tables here
            }

            if ($index === null || $record === null) {
                continue;
            }

            try {
                Livewire::test($index->getPage())->mountTableAction('edit', $record)->assertSuccessful();
                $opened[] = class_basename($resource);
            } catch (Throwable $e) {
                // An edit link to its own page is not a window: nothing to open here.
                if (! str_contains($e->getMessage(), 'edit') || str_contains($e->getMessage(), 'Property type not supported')) {
                    $failed[] = class_basename($resource).': '.$e->getMessage();
                }
            }
        }

        $this->assertSame([], $failed);
        $this->assertContains('ItemResource', $opened);
        $this->assertGreaterThanOrEqual(8, count($opened), 'Too few edit windows were tried: '.implode(', ', $opened));
    }

    public function test_every_screen_opens_with_every_module_and_two_branches_on(): void
    {
        $clinic = $this->createClinic('alpha');
        $this->artisan('clinic:demo-data', ['clinic' => $clinic->id, '--password' => 'sample-pass-123', '--force' => true])->assertSuccessful();

        app(ClinicManager::class)->activate($clinic);
        $owner = $this->owner();
        $failed = [];
        $opened = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{') || $route->getDomain() !== null
                || preg_match('#^(livewire|_|storage|up$|login|logout|export|print|database-updates|platform|reports$|profile/)#', $uri)
                || ! in_array('panel:admin', $route->gatherMiddleware(), true)) {
                continue;
            }

            $response = $this->signIn($owner)->get('http://alpha.test/'.$uri);
            $opened++;

            // Not screens: the demo module is off on purpose, and the old order form's address opens the billing screen.
            $expected = ['demo-notes' => 403, 'orders/create' => 302][$uri] ?? 200;

            if ($response->status() !== $expected) {
                $failed[] = "{$uri}: {$response->status()}";
            }
        }

        $this->assertSame([], $failed);
        $this->assertGreaterThan(40, $opened, 'Too few screens were tried.');
    }

    /** @return list<class-string> */
    private static function resources(): array
    {
        return Filament::getPanel('admin')->getResources();
    }
}
