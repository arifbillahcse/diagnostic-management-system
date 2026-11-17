<?php

declare(strict_types=1);

namespace Modules\Analyzers\Filament;

use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Modules\GatesModuleResource;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Analyzers\Actions\ManageMachines;
use Modules\Analyzers\Actions\ReceiveResults;
use Modules\Analyzers\Models\Machine;
use Modules\Analyzers\Models\Message;
use Modules\Analyzers\Permissions;
use UnitEnum;

class MachineResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Machine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Lab machines';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Machines';

    protected static ?string $modelLabel = 'lab machine';

    protected static ?string $slug = 'lab-machines';

    public static function moduleKey(): string
    {
        return 'analyzers';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::MACHINES_MANAGE : null;
    }

    /** @return list<Field> */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(100)->placeholder('Haematology analyzer'),
            TextInput::make('model')->maxLength(100)->placeholder('Brand and model, as on the machine'),
            Select::make('protocol')->required()->native(false)->options(Machine::PROTOCOLS)
                ->helperText('From the machine\'s interface manual (LIS / host interface).'),
            Select::make('branch_id')->label('Branch')->required()->native(false)->options(fn (): array => Branch::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('user_id')->label('Results are saved under')->required()->searchable()
                ->options(fn (): array => User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('A lab account that may enter results. Results arrive as drafts under it; a person still submits and verifies.'),
        ];
    }

    /** The key, shown once, with how to use it. */
    public static function showKey(string $key): void
    {
        Notification::make()->success()->persistent()->title('Copy this key now')
            ->body("{$key}\n\nPut it in the bridge program's settings on the lab PC. It is shown only now; if it is lost, replace it.")
            ->send();
    }

    public static function table(Table $table): Table
    {
        $tz = app(ClinicContext::class)->timezone();

        return $table
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (Machine $r): ?string => $r->model),
                TextColumn::make('protocol')->formatStateUsing(fn (string $state): string => Machine::PROTOCOLS[$state] ?? $state),
                TextColumn::make('mappings_count')->counts('mappings')->label('Codes mapped')->alignEnd(),
                TextColumn::make('token_hint')->label('Key ends with')->formatStateUsing(fn (string $state): string => '…'.$state),
                TextColumn::make('last_seen_at')->label('Last message')->since($tz)->placeholder('Never'),
                IconColumn::make('is_active')->label('On')->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([
                    Action::make('edit')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)
                        ->fillForm(fn (Machine $record): array => $record->only(['name', 'model', 'protocol', 'branch_id', 'user_id', 'is_active']))
                        ->schema(fn (): array => [...static::fields(), Toggle::make('is_active')->label('On (accepts messages)')])
                        ->action(fn (Action $action, Machine $record, array $data) => Guard::run($action, fn () => app(ManageMachines::class)->update($record, $data), 'Machine saved')),
                    Action::make('codes')->label('Test codes')->icon(Heroicon::OutlinedArrowsRightLeft)
                        ->url(fn (Machine $record): string => MappingResource::getUrl('index', ['filters' => ['machine_id' => ['value' => $record->id]]])),
                    Action::make('try')->label('Try a message')->icon(Heroicon::OutlinedBeaker)
                        ->modalDescription('Paste output recorded from the machine. It is handled exactly as if the bridge had sent it.')
                        ->schema([Textarea::make('raw')->label('Machine output')->required()->rows(10)])
                        ->action(function (Machine $record, array $data): void {
                            $message = app(ReceiveResults::class)->receive($record, str_replace("\n", "\r", (string) $data['raw']));
                            Notification::make()->title('Message: '.(Message::STATUSES[$message->status] ?? $message->status))
                                ->body($message->note ?? ($message->sample_codes ? 'Sample '.$message->sample_codes : 'No sample number found.'))
                                ->color(Message::color($message->status))->send();
                        }),
                    Action::make('key')->label('Replace key')->icon(Heroicon::OutlinedKey)->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('A new key is made and the old one stops working at once. The bridge needs the new key.')
                        ->action(function (Action $action, Machine $record): void {
                            $key = Guard::run($action, fn () => app(ManageMachines::class)->replaceKey($record), 'Key replaced');

                            if ($key !== null) {
                                static::showKey($key);
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMachines::route('/')];
    }
}
