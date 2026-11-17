<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament;

use App\Core\Branches\Branch;
use App\Core\Modules\GatesModuleAccess;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Modules\Laboratory\Actions\SaveLabRoutes;
use Modules\Laboratory\Permissions;
use UnitEnum;

/** Which branch tests the samples taken at each branch. */
class CollectionCentres extends Page
{
    use GatesModuleAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Lab set-up';

    protected static ?int $navigationSort = 85;

    protected static ?string $navigationLabel = 'Collection centres';

    protected static ?string $title = 'Collection centres';

    protected static ?string $slug = 'lab-setup/collection-centres';

    protected string $view = 'laboratory::settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function moduleKey(): string
    {
        return 'laboratory';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::SETTINGS_MANAGE;
    }

    public static function canAccess(): bool
    {
        return static::moduleAllows() && Branch::where('is_active', true)->count() > 1;
    }

    public function mount(): void
    {
        $routes = DB::connection('clinic')->table('lab_routes')->pluck('lab_branch_id', 'branch_id');
        $this->form->fill(['routes' => Branch::orderBy('name')->pluck('id')->mapWithKeys(fn ($id): array => [$id => (int) ($routes[$id] ?? $id)])->all()]);
    }

    public function form(Schema $schema): Schema
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();

        return $schema->statePath('data')->components([
            Section::make('Where samples are tested')
                ->description('A collection centre takes samples and sends them to another branch\'s laboratory. Choose the branch itself for a branch with its own laboratory. Only new samples follow a change.')
                ->columns(2)
                ->schema(array_map(fn (int $id, string $name): Select => Select::make("routes.{$id}")->label("Samples taken at {$name} are tested at")
                    ->options($branches)->required()->native(false), array_keys($branches), $branches)),
        ]);
    }

    public function save(): void
    {
        Concerns::attempt(fn () => app(SaveLabRoutes::class)->handle($this->form->getState()['routes'] ?? []), 'Saved');
    }
}
