<?php

declare(strict_types=1);

namespace Modules\Laboratory\Tests;

use Carbon\CarbonImmutable;
use Modules\Laboratory\Enums\Flag;
use Modules\Laboratory\Enums\ResultType;
use Modules\Laboratory\Models\Analyte;
use Modules\Laboratory\Support\Decimal;
use Modules\Laboratory\Support\LabException;
use Modules\Laboratory\Support\RangeFinder;
use Modules\Laboratory\Support\ResultBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

final class ResultLogicTest extends LabTestCase
{
    #[DataProvider('decimals')]
    public function test_lab_values_are_parsed_and_formatted_exactly(string $typed, int $micro, int $decimals, string $shown): void
    {
        $this->assertTrue(Decimal::isValid($typed));
        $this->assertSame($micro, Decimal::micro($typed));
        $this->assertSame($shown, Decimal::format($micro, $decimals));
    }

    public static function decimals(): array
    {
        return [
            ['13.5', 13_500_000, 1, '13.5'],
            ['13.5', 13_500_000, 2, '13.50'],
            ['7500', 7_500_000_000, 0, '7500'],
            ['0.045', 45_000, 2, '0.05'],   // rounds half up
            ['0.044', 44_000, 2, '0.04'],
            ['11.95', 11_950_000, 1, '12.0'],
            ['-2.5', -2_500_000, 1, '-2.5'],
            ['-0.001', -1_000, 1, '0.0'],   // no "-0.0"
            ['0.000001', 1, 6, '0.000001'],
            ['999999999999.999999', 999_999_999_999_999_999, 2, '1000000000000.00'],
        ];
    }

    public function test_values_that_are_not_numbers_are_refused(): void
    {
        foreach (['', 'abc', '1,5', '1.2.3', '1e5', '.5', '12.', '1.1234567', '1 2', '<5'] as $bad) {
            $this->assertFalse(Decimal::isValid($bad), "[{$bad}]");
        }

        $this->expectException(LabException::class);
        app(ResultBuilder::class)->build($this->hb->load('ranges'), '12,5', $this->labPatient(), now());
    }

    private function hbFor(string $sex, string $dob, string $value, string $on = '2026-11-17'): array
    {
        return app(ResultBuilder::class)->build($this->hb->load('ranges'), $value, $this->labPatient($sex, $dob), CarbonImmutable::parse($on));
    }

    public function test_the_range_follows_the_patients_sex_and_age(): void
    {
        $man = $this->hbFor('male', '1990-01-01', '12.5');
        $woman = $this->hbFor('female', '1990-01-01', '12.5');
        $child = $this->hbFor('male', '2020-01-01', '12.5');

        $this->assertSame(['13.0 - 17.0', Flag::Low], [$man['ref_text'], $man['flag']]);
        $this->assertSame(['12.0 - 15.0', Flag::Normal], [$woman['ref_text'], $woman['flag']]);
        $this->assertSame(['11.0 - 14.0', Flag::Normal], [$child['ref_text'], $child['flag']]);

        $this->assertSame('12.500000', $man['value_numeric']);
        $this->assertSame('12.5', $man['value_text']);
        $this->assertSame('g/dL', $man['unit']);
        $this->assertSame('13.000000', $man['ref_low']);
    }

    public function test_the_age_is_taken_on_the_collection_date(): void
    {
        // Eighteenth birthday (6,570 days) falls between the two dates.
        $before = $this->hbFor('male', '2008-12-01', '12.5', '2026-11-17');
        $after = $this->hbFor('male', '2008-12-01', '12.5', '2027-01-15');

        $this->assertSame('11.0 - 14.0', $before['ref_text']);
        $this->assertSame('13.0 - 17.0', $after['ref_text']);
    }

    public function test_a_patient_of_another_sex_falls_back_to_a_general_range_or_none(): void
    {
        $adult = $this->hbFor('other', '1990-01-01', '12.5');
        $this->assertSame([null, Flag::None], [$adult['ref_text'], $adult['flag']], 'no general adult range is defined');

        $child = $this->hbFor('other', '2020-01-01', '12.5');
        $this->assertSame('11.0 - 14.0', $child['ref_text']);
    }

    #[DataProvider('flags')]
    public function test_flags_are_calculated_from_the_limits(string $value, Flag $expected): void
    {
        $this->assertSame($expected, $this->hbFor('male', '1990-01-01', $value)['flag']);
    }

    public static function flags(): array
    {
        return [
            'well inside' => ['15.0', Flag::Normal],
            'equal to the low limit is normal' => ['13.0', Flag::Normal],
            'equal to the high limit is normal' => ['17.0', Flag::Normal],
            'just under' => ['12.9', Flag::Low],
            'just over' => ['17.1', Flag::High],
            'equal to the critical limit is only low' => ['7.0', Flag::Low],
            'under the critical limit' => ['6.9', Flag::CriticalLow],
            'over the critical limit' => ['20.1', Flag::CriticalHigh],
            'a tiny difference still counts' => ['12.999999', Flag::Low],
        ];
    }

    public function test_one_sided_ranges_and_text_ranges_print_sensibly(): void
    {
        $build = fn (Analyte $a, string $v): array => app(ResultBuilder::class)->build($a->load('ranges'), $v, $this->labPatient(), now());

        $rbs = Analyte::where('code', 'RBS')->first();
        $this->assertSame(['Up to 7.8', Flag::Normal], [$build($rbs, '7.8')['ref_text'], $build($rbs, '7.8')['flag']]);
        $this->assertSame(Flag::High, $build($rbs, '7.9')['flag']);

        $egfr = $this->analyte('EGFR', 'eGFR', 'mL/min', 0, [['low' => '90']]);
        $this->assertSame(['90 or more', Flag::Low], [$build($egfr, '60')['ref_text'], $build($egfr, '60')['flag']]);

        $tsh = $this->analyte('TSH', 'TSH', 'mIU/L', 2, [['low' => '0.4', 'high' => '4.0', 'text_range' => '0.40 - 4.00 (adult)']]);
        $this->assertSame('0.40 - 4.00 (adult)', $build($tsh, '2')['ref_text']);
        $this->assertSame('2.00', $build($tsh, '2')['value_text']);

        $plain = $this->analyte('XYZ', 'No range yet', 'U', 1);
        $this->assertSame([null, Flag::None], [$build($plain, '5')['ref_text'], $build($plain, '5')['flag']]);
    }

    public function test_overlapping_ranges_resolve_to_the_most_specific_and_dated_ranges_apply_by_date(): void
    {
        $analyte = $this->analyte('ALP', 'ALP', 'U/L', 0, [
            ['low' => '40', 'high' => '130'],
            ['age_min_days' => 0, 'age_max_days' => 6569, 'low' => '100', 'high' => '400'],
            ['low' => '30', 'high' => '120', 'effective_from' => '2027-01-01'],
            ['low' => '50', 'high' => '150', 'effective_to' => '2020-12-31'],
        ])->load('ranges');

        $find = fn (int $age, string $on) => app(RangeFinder::class)->find($analyte, 'male', $age, CarbonImmutable::parse($on));

        $this->assertSame('400.000000', number_format((float) $find(3000, '2026-06-01')->high, 6, '.', ''), 'the child band is narrower');
        $this->assertSame('130.000000', number_format((float) $find(12000, '2026-06-01')->high, 6, '.', ''));
        $this->assertSame('120.000000', number_format((float) $find(12000, '2027-06-01')->high, 6, '.', ''), 'the newer of two equal bands');
        $this->assertSame('150.000000', number_format((float) $find(12000, '2020-06-01')->high, 6, '.', ''));
    }

    public function test_choice_and_text_results(): void
    {
        $build = fn (string $code, string $v): array => app(ResultBuilder::class)->build(Analyte::where('code', $code)->first(), $v, $this->labPatient(), now());

        $this->assertSame(['Nil', Flag::Normal, 'Nil'], [$build('USUG', 'Nil')['value_text'], $build('USUG', 'Nil')['flag'], $build('USUG', 'Nil')['ref_text']]);
        $this->assertSame(Flag::Abnormal, $build('USUG', '++')['flag']);
        $this->assertSame(Flag::None, $build('UCOL', 'Straw')['flag']);

        try {
            $build('USUG', 'Plenty');
            $this->fail('An answer outside the list should be refused.');
        } catch (LabException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LabException::class);
        $build('UCOL', str_repeat('x', 256));
    }

    public function test_a_choice_analyte_without_abnormal_answers_is_never_flagged(): void
    {
        $analyte = Analyte::create(['code' => 'BG', 'name' => 'Blood group', 'result_type' => ResultType::Option, 'options' => ['A', 'B', 'AB', 'O']]);
        $built = app(ResultBuilder::class)->build($analyte, 'AB', $this->labPatient(), now());

        $this->assertSame([Flag::None, null], [$built['flag'], $built['ref_text']]);
    }
}
