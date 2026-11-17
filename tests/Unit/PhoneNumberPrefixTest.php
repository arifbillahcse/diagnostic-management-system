<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Patients\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNumberPrefixTest extends TestCase
{
    /** @return array<string, array{?string, ?string}> */
    public static function typed(): array
    {
        return [
            'start of a local number' => ['01822', '8801822'],
            'full local number' => ['01822-334455', '8801822334455'],
            'without the leading zero' => ['1822', '8801822'],
            'already international' => ['+880 1822', '8801822'],
            'with 00880' => ['008801822', '8801822'],
            'something else' => ['4455', '4455'],
            'empty' => ['', null],
            'letters only' => ['abc', null],
            'nothing' => [null, null],
        ];
    }

    #[DataProvider('typed')]
    public function test_a_partly_typed_number_becomes_the_start_of_the_stored_one(?string $typed, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::prefix($typed));
    }
}
