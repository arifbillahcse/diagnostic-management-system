<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Core\Auth\Actions\SaveUser;
use App\Core\Auth\LastOwnerException;
use App\Filament\Resources\Users\UserResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role_ids'] = DB::connection('clinic')->table('user_roles')
            ->where('user_id', $this->getRecord()->getKey())
            ->whereNull('branch_id')
            ->pluck('role_id')
            ->all();

        unset($data['password']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(SaveUser::class)->update($record, $data);
        } catch (LastOwnerException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
