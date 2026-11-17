<?php

declare(strict_types=1);

namespace Modules\Analyzers\Tests;

use Modules\Analyzers\Support\AnalyzerException;
use Modules\Analyzers\Support\Parsers\AstmParser;
use Modules\Analyzers\Support\Parsers\Hl7Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private function fixture(string $name, string $sample = '2611180001'): string
    {
        return str_replace(['{SAMPLE}', "\n"], [$sample, "\r"], trim((string) file_get_contents(__DIR__.'/fixtures/'.$name)));
    }

    public function test_astm_records_give_the_specimen_and_its_results(): void
    {
        $reading = (new AstmParser)->parse($this->fixture('astm-cbc.txt'));

        $this->assertCount(1, $reading->specimens);
        $this->assertSame(['2611180001'], $reading->specimens[0]['codes']);
        $this->assertSame(['HGB', 'WBC', 'MCV'], array_column($reading->specimens[0]['results'], 'code'));
        $this->assertSame('13.5', $reading->specimens[0]['results'][0]['value']);
        $this->assertSame('g/dL', $reading->specimens[0]['results'][0]['unit']);
        $this->assertSame('N', $reading->specimens[0]['results'][0]['flag']);
    }

    public function test_astm_low_level_framing_and_two_specimens_are_handled(): void
    {
        $framed = "\x05\x021H|\\^&|||Analyzer\r\x0350\r\n\x022O|1|1111||^^^CBC\rR|1|^^^HGB|12.1|g/dL\r\x03A1\r\n"
            ."\x023O|2|2222||^^^CBC\rR|1|^^^HGB|9.8|g/dL\rL|1|N\r\x03B2\r\n\x04";

        $reading = (new AstmParser)->parse($framed);

        $this->assertSame([['1111'], ['2222']], array_column($reading->specimens, 'codes'));
        $this->assertSame('9.8', $reading->specimens[1]['results'][0]['value']);
    }

    public function test_hl7_gives_the_specimen_codes_and_skips_images(): void
    {
        $reading = (new Hl7Parser)->parse("\x0b".$this->fixture('hl7-cbc.txt')."\x1c\r");

        $this->assertSame(['2611180001'], $reading->specimens[0]['codes']);
        $results = $reading->specimens[0]['results'];
        $this->assertCount(2, $results, 'The histogram image is not a result');
        $this->assertSame(['718-7', 'HGB'], [$results[0]['code'], $results[0]['alt']]);
        $this->assertSame('8200', $results[1]['value']);
    }

    public function test_messages_of_the_wrong_kind_are_refused(): void
    {
        foreach ([fn () => (new AstmParser)->parse('MSH|^~\&|x'), fn () => (new Hl7Parser)->parse('H|\^&|x')] as $try) {
            try {
                $try();
                $this->fail('Read a message of the wrong kind');
            } catch (AnalyzerException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
