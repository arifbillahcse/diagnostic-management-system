<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Core\Auth\Actions\SaveRole;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        // Only keys offered on the form are accepted, whatever was posted.
        $keys = array_intersect($data['permission_keys'] ?? [], array_keys(RoleResource::grantablePermissions()));

        return app(SaveRole::class)->create($data['name'], array_values($keys));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
