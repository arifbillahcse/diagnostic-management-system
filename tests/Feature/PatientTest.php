<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit\AuditLog;
use App\Core\Branches\Branch;
use App\Core\Patients\Actions\SavePatient;
use App\Core\Patients\DuplicateFinder;
use App\Core\Patients\Patient;
use App\Core\Patients\PatientRegistered;
use App\Core\Patients\PhoneNumber;
use App\Core\Patients\Sex;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

final class PatientTest extends TestCase
{
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useClinic();
        $this->branch = Branch::firstOrFail();
        $this->actingAs($this->owner());
    }

    private function register(array $data): Patient
    {
        return app(SavePatient::class)->register($data + ['name' => 'Rahim Uddin', 'sex' => 'male', 'date_of_birth' => '1990-05-20'], $this->branch);
    }

    public function test_phone_numbers_are_normalised_to_one_form(): void
    {
        foreach (['01711-000000', '+880 1711 000000', '8801711000000', '008801711000000', '1711000000'] as $typed) {
            $this->assertSame('8801711000000', PhoneNumber::normalize($typed), $typed);
        }

        $this->assertNull(PhoneNumber::normalize(''));
        $this->assertNull(PhoneNumber::normalize('n/a'));
        $this->assertSame('02123456', PhoneNumber::normalize('02-123456'), 'a land line is kept as typed');
    }

    public function test_registering_gives_a_code_and_writes_an_audit_entry(): void
    {
        Event::fake([PatientRegistered::class]);

        $first = $this->register(['phone' => '01711-000000', 'address' => '  Khulna  ']);
        $second = $this->register(['name' => 'Salma Begum', 'sex' => 'female']);

        $this->assertMatchesRegularExpression('/^P-\d{2}-000001$/', $first->patient_code);
        $this->assertStringEndsWith('-000002', $second->patient_code);
        $this->assertSame('8801711000000', $first->phone_normalized);
        $this->assertSame('Khulna', $first->address);
        $this->assertSame(Sex::Male, $first->sex);
        $this->assertFalse($first->dob_is_estimated);
        $this->assertSame($this->branch->id, $first->registered_branch_id);

        $entry = AuditLog::where('action', 'patient.registered')->orderBy('id')->first();
        $this->assertSame($first->id, $entry->entity_id);
        $this->assertSame('1990-05-20', $entry->after['date_of_birth']);
        Event::assertDispatchedTimes(PatientRegistered::class, 2);
    }

    public function test_an_age_is_turned_into_an_estimated_date_of_birth(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Dhaka'));

        $adult = $this->register(['date_of_birth' => null, 'age_years' => 34]);
        $infant = $this->register(['date_of_birth' => null, 'age_months' => 3, 'age_days' => 12]);

        $this->assertSame('1992-11-16', $adult->date_of_birth->toDateString());
        $this->assertTrue($adult->dob_is_estimated);
        $this->assertSame('34 Y', $adult->ageLabel());

        $this->assertSame('2026-08-04', $infant->date_of_birth->toDateString());
        $this->assertSame('3 M', $infant->ageLabel());
        $this->assertSame(104, $infant->ageInDays());

        // A year later the same record gives the right age without re-entry.
        $this->assertSame('35 Y', $adult->ageLabel(CarbonImmutable::parse('2027-11-20')));
    }

    public function test_a_birth_date_or_age_is_required_and_must_be_sensible(): void
    {
        foreach ([
            ['date_of_birth' => null],
            ['date_of_birth' => now()->addDay()->toDateString()],
            ['date_of_birth' => null, 'age_years' => 131],
            ['date_of_birth' => null, 'age_years' => -1],
        ] as $data) {
            try {
                $this->register($data);
                $this->fail('Should have been refused: '.json_encode($data));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Patient::count());
    }

    public function test_updating_records_what_changed_and_keeps_the_code(): void
    {
        $patient = $this->register(['phone' => '01711000000']);

        app(SavePatient::class)->update($patient, ['name' => 'Rahim Uddin Khan', 'phone' => '01811000000']);

        $patient->refresh();
        $this->assertSame('8801811000000', $patient->phone_normalized);
        $this->assertSame('1990-05-20', $patient->date_of_birth->toDateString(), 'untouched when not sent');

        $entry = AuditLog::where('action', 'patient.updated')->sole();
        $this->assertSame(['name' => 'Rahim Uddin', 'phone' => '01711000000'], $entry->before);

        $count = AuditLog::count();
        app(SavePatient::class)->update($patient, ['name' => 'Rahim Uddin Khan']);
        $this->assertSame($count, AuditLog::count());
    }

    public function test_possible_duplicates_are_found_by_phone_and_never_merged(): void
    {
        $father = $this->register(['name' => 'Abdul Karim', 'phone' => '01711-000000']);
        $son = $this->register(['name' => 'Rafi Karim', 'phone' => '+8801711000000']);
        $this->register(['name' => 'Someone Else', 'phone' => '01911000000']);

        $matches = app(DuplicateFinder::class)->byPhone('01711 000000');

        $this->assertSame(['Abdul Karim', 'Rafi Karim'], $matches->pluck('name')->all());
        $this->assertSame(['Rafi Karim'], app(DuplicateFinder::class)->byPhone('01711000000', $father->id)->pluck('name')->all());
        $this->assertCount(0, app(DuplicateFinder::class)->byPhone('017'));
        $this->assertSame(3, Patient::count(), 'a family sharing a phone stays separate people');
        $this->assertNotSame($father->patient_code, $son->patient_code);
    }

    public function test_search_matches_code_name_start_and_phone(): void
    {
        $rahim = $this->register(['name' => 'Rahim Uddin', 'phone' => '01711000000']);
        $this->register(['name' => 'Karim Rahim', 'phone' => '01811000000']);

        $names = fn (string $term) => Patient::search($term)->orderBy('id')->pluck('name')->all();

        $this->assertSame(['Rahim Uddin'], $names('Rah'));
        $this->assertSame(['Rahim Uddin'], $names($rahim->patient_code));
        $this->assertSame(['Rahim Uddin'], $names('01711-000000'));
        $this->assertSame(['Karim Rahim'], $names('+880 1811'));
        $this->assertSame([], $names('him'), 'text in the middle of a name is not searched');
        $this->assertSame([], $names('100%'), 'wildcards typed by a user are literal');
        $this->assertCount(2, $names(''));
    }

    public function test_permissions_are_enforced(): void
    {
        $patient = $this->register([]);

        $this->actingAs($this->userWithRoles('lab_technician'));

        try {
            $this->register(['name' => 'Not Allowed']);
            $this->fail('A lab technician cannot register patients.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(AuthorizationException::class);
        app(SavePatient::class)->update($patient, ['name' => 'Changed']);
    }
}
