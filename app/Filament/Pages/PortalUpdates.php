<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Core\Tenancy\PendingUpdates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * TEMPORARY (trial period): shows the database updates waiting to be applied
 * and applies them. Only the owner sees it, and only while
 * DMS_PORTAL_UPDATES=true. See the README for how to remove it.
 */
class PortalUpdates extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 95;

    protected static ?string $navigationLabel = 'Database updates';

    protected static ?string $title = 'Database updates';

    protected static ?string $slug = 'database-updates';

    protected string $view = 'filament.pages.portal-updates';

    public static function canAccess(): bool
    {
        return app(PendingUpdates::class)->allowed();
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = count(app(PendingUpdates::class)->control()) + count(app(PendingUpdates::class)->clinic());
        } catch (Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return list<string> */
    public function getPlatformPendingProperty(): array
    {
        return app(PendingUpdates::class)->control();
    }

    /** @return list<string> */
    public function getClinicPendingProperty(): array
    {
        return app(PendingUpdates::class)->clinic();
    }

    /** A migration file name as readable words: "2026_11_20_100000_add_invoice_details" becomes "Add invoice details". */
    public function label(string $migration): string
    {
        return ucfirst(str_replace('_', ' ', (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $migration)));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('apply')
                ->label('Apply updates')
                ->icon(Heroicon::OutlinedPlay)
                ->visible(fn (): bool => $this->platformPending !== [] || $this->clinicPending !== [])
                ->requiresConfirmation()
                ->modalHeading('Apply the database updates?')
                ->modalDescription('The updates change the structure of your database. Take a backup first if you have real patient data. The page may take a moment.')
                ->modalSubmitActionLabel('Apply now')
                ->action(function (): void {
                    try {
                        $done = app(PendingUpdates::class)->apply();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('The update did not finish')
                            ->body($e->getMessage().' Nothing is lost; fix the cause and apply again.')->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title('Updates applied')
                        ->body((count($done['control']) + count($done['clinic'])).' update(s) applied.')->send();
                }),
        ];
    }
}
