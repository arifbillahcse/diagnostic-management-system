<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Plans;

use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\Plan;
use App\Platform\Actions\SavePlan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

/** The editions on sale: prices, branch limit, modules. */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'plans';

    /** @return array<string, string> */
    private static function moduleOptions(): array
    {
        $registry = app(ModuleRegistry::class);

        return ['*' => 'Every module (now and future)'] + collect(array_keys($registry->all()))->mapWithKeys(fn (string $k): array => [$k => $registry->get($k)->name." ({$k})"])->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->description(fn (Plan $p): ?string => $p->description)->wrap(),
                TextColumn::make('price_monthly')->label('Monthly')->alignEnd()->formatStateUsing(fn ($state): string => '৳ '.$state->formatted()),
                TextColumn::make('price_yearly')->label('Yearly')->alignEnd()->formatStateUsing(fn ($state): string => '৳ '.$state->formatted()),
                TextColumn::make('max_branches')->label('Branches')->placeholder('Any'),
                TextColumn::make('clinics')->state(fn (Plan $p): int => Clinic::where('plan_id', $p->id)->count())->alignEnd(),
                IconColumn::make('is_active')->label('On sale')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                Action::make('edit')->label('Edit')
                    ->fillForm(fn (Plan $p): array => [
                        'name' => $p->name, 'description' => $p->description,
                        'price_monthly' => $p->price_monthly->toDecimal(), 'price_yearly' => $p->price_yearly->toDecimal(),
                        'max_branches' => $p->max_branches, 'modules' => $p->moduleKeys(), 'is_active' => $p->is_active,
                    ])
                    ->modalDescription('Clinics on this plan follow at once. Modules removed from the plan stay on in clinics that use them until their plan is changed.')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(100),
                        TextInput::make('description')->maxLength(255),
                        TextInput::make('price_monthly')->label('Monthly price')->prefix('৳')->required()->rule('regex:/^\d{1,10}(\.\d{1,2})?$/'),
                        TextInput::make('price_yearly')->label('Yearly price')->prefix('৳')->required()->rule('regex:/^\d{1,10}(\.\d{1,2})?$/'),
                        TextInput::make('max_branches')->label('Most branches (empty: any)')->numeric()->minValue(1)->maxValue(999),
                        CheckboxList::make('modules')->options(fn (): array => static::moduleOptions())->columns(2)->required(),
                        Toggle::make('is_active')->label('On sale'),
                    ])
                    ->action(function (Action $action, Plan $record, array $data): void {
                        try {
                            app(SavePlan::class)->handle($record, $data + ['description' => null, 'max_branches' => null]);
                            Notification::make()->success()->title('Plan saved')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
                            $action->halt();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPlans::route('/')];
    }
}
