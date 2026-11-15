<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Core\Auth\Actions\SaveRole;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permission_keys'] = array_values(array_intersect(
            $this->getRecord()->permissions()->pluck('key')->all(),
            array_keys(RoleResource::grantablePermissions()),
        ));

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $grantable = array_keys(RoleResource::grantablePermissions());
        $chosen = array_intersect($data['permission_keys'] ?? [], $grantable);

        // Permissions of modules that are currently switched off are not on
        // the form. Keep them, so they are still there when the module is
        // switched back on.
        $hidden = array_diff($record->permissions()->pluck('key')->all(), $grantable);

        return app(SaveRole::class)->update($record, $data['name'], array_values([...$chosen, ...$hidden]));
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
