<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Core\Audit\AuditLogger;
use Filament\Auth\Pages\EditProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Where a user changes their own password and sets up two-step sign-in.
 *
 * Name, email, and roles are not here: those are changed by someone with
 * the user-management permission, so a person cannot rename themselves or
 * take over another email address.
 */
class MyAccount extends EditProfile
{
    public static function getLabel(): string
    {
        return 'My account';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getPasswordFormComponent()->label('New password')->helperText('At least 10 characters. Leave empty to keep your current password.'),
            $this->getPasswordConfirmationFormComponent()->label('New password again'),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Only the password can change here, whatever was submitted.
        $data = array_intersect_key($data, ['password' => true]);

        if ($data === []) {
            return $record;
        }

        return DB::connection('clinic')->transaction(function () use ($record, $data): Model {
            // Already hashed by the form component.
            $record->forceFill(['password' => $data['password']])->save();
            app(AuditLogger::class)->record('user.password_changed', $record);

            return $record;
        });
    }
}
