<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Core\Auth\CorePermissions;
use App\Core\Modules\Actions\DisableModule;
use App\Core\Modules\Actions\EnableModule;
use App\Core\Modules\ModuleException;
use App\Core\Modules\ModuleManager as Modules;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Lets a clinic admin switch modules on and off.
 */
class ModuleManager extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Modules';

    protected static ?string $title = 'Modules';

    protected static ?string $slug = 'modules';

    protected string $view = 'filament.pages.module-manager';

    public static function canAccess(): bool
    {
        return Gate::allows(CorePermissions::MODULES_MANAGE);
    }

    /** @return list<array<string, mixed>> */
    public function getModulesProperty(): array
    {
        return app(Modules::class)->overview();
    }

    public function enable(string $key, EnableModule $action): void
    {
        $this->switch(fn () => $action->handle($key), 'Module switched on');
    }

    public function disable(string $key, DisableModule $action): void
    {
        $this->switch(fn () => $action->handle($key), 'Module switched off', 'Its data is kept and returns when you switch it on again.');
    }

    private function switch(callable $change, string $title, ?string $body = null): void
    {
        try {
            $change();
        } catch (ModuleException $e) {
            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();

        // Reload so the menu shows or hides the module's screens.
        $this->redirect(static::getUrl());
    }
}
