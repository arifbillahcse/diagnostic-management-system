<?php

declare(strict_types=1);

namespace App\Filament\Resources\Patients\Pages;

use App\Core\Branches\CurrentBranch;
use App\Core\Patients\Actions\SavePatient;
use App\Filament\Resources\Patients\PatientResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreatePatient extends CreateRecord
{
    protected static string $resource = PatientResource::class;

    protected static ?string $title = 'Register patient';

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SavePatient::class)->register($data, app(CurrentBranch::class)->get());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
