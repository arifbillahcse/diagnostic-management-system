<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Core\Auth\CorePermissions;
use App\Core\Export\DataExporter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Lets the clinic download all of its own data.
 */
class DataExport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Data export';

    protected static ?string $title = 'Data export';

    protected static ?string $slug = 'data-export';

    protected string $view = 'filament.pages.data-export';

    public static function canAccess(): bool
    {
        return Gate::allows(CorePermissions::EXPORT_RUN);
    }

    /** @return list<array{table: string, label: string, rows: int}> */
    public function getDatasetsProperty(): array
    {
        $exporter = app(DataExporter::class);
        $list = [];

        foreach ($exporter->datasets() as $table => $label) {
            $list[] = ['table' => $table, 'label' => $label, 'rows' => $exporter->count($table)];
        }

        return $list;
    }
}
