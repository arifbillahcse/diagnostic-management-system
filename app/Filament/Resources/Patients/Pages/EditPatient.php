<?php

declare(strict_types=1);

namespace App\Filament\Resources\Patients\Pages;

use App\Core\Patients\Actions\SavePatient;
use App\Filament\Resources\Patients\PatientResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class EditPatient extends EditRecord
{
    protected static string $resource = PatientResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // An estimated birth date is shown back as the age it came from, so
        // staff are not misled into reading it as a known date.
        if ($this->getRecord()->dob_is_estimated) {
            $diff = $this->getRecord()->age();
            $data['date_of_birth'] = null;
            $data += ['age_years' => $diff->y, 'age_months' => $diff->m, 'age_days' => $diff->d];
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(SavePatient::class)->update($record, $data);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
