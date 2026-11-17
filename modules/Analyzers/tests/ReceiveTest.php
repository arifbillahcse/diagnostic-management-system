<?php

declare(strict_types=1);

namespace Modules\Analyzers\Tests;

use App\Core\Audit\AuditLog;
use LogicException;
use Modules\Analyzers\Actions\ManageMachines;
use Modules\Analyzers\Actions\ReceiveResults;
use Modules\Analyzers\Models\MachineResult;
use Modules\Analyzers\Models\Message;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\Result;

class ReceiveTest extends AnalyzerTestCase
{
    private function receive(string $raw, $machine = null): Message
    {
        return app(ReceiveResults::class)->receive($machine ?? $this->machine, $raw);
    }

    public function test_results_are_saved_as_a_draft_never_submitted(): void
    {
        $order = $this->cbcInLab();
        $message = $this->receive($this->fixture('astm-cbc.txt', $this->sampleNo($order)));

        $this->assertSame('partial', $message->status, 'MCV is not mapped');
        $work = $this->work($order, $this->cbc)->fresh();
        $this->assertSame(WorkStatus::Ready, $work->status, 'Draft only: a person submits');
        $this->assertSame(['Haemoglobin' => '13.5', 'Total WBC count' => '8200'], Result::where('work_item_id', $work->id)->pluck('value_text', 'analyte_name')->all());
        $this->assertSame($this->technician->id, $work->entered_by, 'Saved under the machine\'s lab account');

        $this->assertSame(['applied' => 2, 'unmapped' => 1], MachineResult::where('message_id', $message->id)->pluck('status')->countBy()->sortKeys()->all());
        $this->assertTrue(AuditLog::where('action', 'analyzer.results_saved')->exists());

        $this->enter($work, ['HB' => '13.5', 'WBC' => '8200']);
        $this->assertSame(WorkStatus::Entered, $work->fresh()->status, 'A technician submits as usual');
    }

    public function test_hl7_results_are_matched_by_code_or_short_name(): void
    {
        [$hl7] = $this->machine('Chemistry analyzer', 'hl7');
        $order = $this->cbcInLab();

        $message = $this->receive($this->fixture('hl7-cbc.txt', $this->sampleNo($order)), $hl7);

        $this->assertSame('applied', $message->status);
        $this->assertSame('13.5', Result::where('work_item_id', $this->work($order, $this->cbc)->id)->where('analyte_name', 'Haemoglobin')->value('value_text'));
    }

    public function test_typed_values_stay_and_the_machine_value_replaces_its_own_analyte(): void
    {
        $order = $this->cbcInLab();
        $work = $this->work($order, $this->cbc);
        $this->enter($work, ['HB' => '12.0', 'WBC' => '6000'], submit: false);

        $this->receive("H|\\^&\rO|1|{$this->sampleNo($order)}||^^^CBC\rR|1|^^^HGB|13.5|g/dL\rL|1|N");

        $this->assertSame(['Haemoglobin' => '13.5', 'Total WBC count' => '6000'], Result::where('work_item_id', $work->id)->pluck('value_text', 'analyte_name')->all());
    }

    public function test_what_cannot_be_saved_is_kept_with_the_reason(): void
    {
        $waiting = $this->labOrder([$this->cbc]); // sample not received
        $done = $this->releasedCbc()->order;
        $glucose = $this->labOrder([$this->glucose]);
        $this->receiveSamples($glucose);

        $cases = [
            ['9999999999', 'no_sample'],
            [$this->sampleNo($waiting), 'not_received'],
            [$this->sampleNo($done), 'already_submitted'],
            [$this->sampleNo($glucose), 'no_test'],
        ];

        foreach ($cases as [$sample, $status]) {
            $message = $this->receive("H|\\^&\rO|1|{$sample}||^^^CBC\rR|1|^^^HGB|13.5|g/dL\rL|1|N");
            $this->assertSame('unmatched', $message->status);
            $this->assertSame($status, $message->results()->value('status'), $sample);
        }
    }

    public function test_the_same_message_twice_is_stored_and_saved_once(): void
    {
        $raw = $this->fixture('astm-cbc.txt', $this->sampleNo($this->cbcInLab()));

        $first = $this->receive($raw);
        $second = $this->receive($raw);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Message::count());
        $this->assertSame(1, AuditLog::where('action', 'analyzer.results_saved')->count());
    }

    public function test_an_unreadable_message_is_kept_and_messages_never_change(): void
    {
        $message = $this->receive('garbage from a loose cable');
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('not an ASTM message', $message->note);

        foreach ([fn () => $message->update(['raw' => 'x']), fn () => $message->delete()] as $try) {
            try {
                $try();
                $this->fail('A machine message changed');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_switched_off_lab_account_saves_nothing_and_says_why(): void
    {
        $this->technician->forceFill(['is_active' => false])->save();
        $message = $this->receive($this->fixture('astm-cbc.txt', $this->sampleNo($this->cbcInLab())));

        $this->assertSame('unmatched', $message->status);
        $this->assertStringContainsString('lab account', MachineResult::where('status', 'failed')->value('note'));
    }

    public function test_a_wrong_barcode_is_matched_by_hand(): void
    {
        $order = $this->cbcInLab();
        $message = $this->receive("H|\\^&\rO|1|MISREAD||^^^CBC\rR|1|^^^HGB|13.5|g/dL\rL|1|N");
        $this->assertSame('unmatched', $message->status);

        $message = app(ManageMachines::class)->rematch($message, $this->sampleNo($order));

        $this->assertSame('applied', $message->status);
        $this->assertSame('13.5', Result::where('work_item_id', $this->work($order, $this->cbc)->id)->value('value_text'));
        $this->assertTrue(AuditLog::where('action', 'analyzer.rematched')->exists());
    }
}
