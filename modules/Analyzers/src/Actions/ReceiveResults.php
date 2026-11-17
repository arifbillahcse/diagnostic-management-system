<?php

declare(strict_types=1);

namespace Modules\Analyzers\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Analyzers\Models\Machine;
use Modules\Analyzers\Models\MachineResult;
use Modules\Analyzers\Models\Mapping;
use Modules\Analyzers\Models\Message;
use Modules\Analyzers\Support\AnalyzerException;
use Modules\Analyzers\Support\Parsers\AstmParser;
use Modules\Analyzers\Support\Parsers\Hl7Parser;
use Modules\Analyzers\Support\Parsers\Reading;
use Modules\Laboratory\Actions\EnterResults;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\Result;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\WorkItem;
use Modules\Laboratory\Support\LabException;
use Throwable;

/**
 * Takes a message from a machine: keeps it exactly as it arrived, reads
 * it, finds each specimen's sample by its number, and saves the mapped
 * values as DRAFT results through the laboratory's own result entry, under
 * the machine's lab account. Nothing is submitted or verified by a machine:
 * a technician submits and a verifier verifies, as for typed results.
 *
 * Values already typed for a test are kept; the machine's values are added
 * or replace that analyte's value. The same message sent twice is stored
 * and applied once.
 */
final class ReceiveResults
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function receive(Machine $machine, string $raw): Message
    {
        $raw = mb_substr($raw, 0, 1_000_000);
        $hash = hash('sha256', $raw);

        $existing = Message::where('machine_id', $machine->id)->where('raw_hash', $hash)->first();

        if ($existing !== null) {
            return $existing;
        }

        $message = Message::create([
            'machine_id' => $machine->id,
            'protocol' => $machine->protocol,
            'raw' => $raw,
            'raw_hash' => $hash,
            'status' => 'unmatched',
            'received_at' => now(),
        ]);

        $machine->forceFill(['last_seen_at' => now()])->saveQuietly();

        try {
            $reading = ($machine->protocol === 'hl7' ? new Hl7Parser : new AstmParser)->parse($raw);
        } catch (AnalyzerException $e) {
            $message->forceFill(['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 255)])->save();

            return $message;
        }

        $this->apply($machine, $message, $reading);

        return $message->refresh();
    }

    /** Reads the stored message again with a sample number typed by staff, for results that found no sample. */
    public function rematch(Message $message, string $sampleCode): Message
    {
        $machine = $message->machine;
        $reading = ($machine->protocol === 'hl7' ? new Hl7Parser : new AstmParser)->parse($message->raw);

        $specimens = array_map(fn (array $s): array => ['codes' => [trim($sampleCode)], 'results' => $s['results']], $reading->specimens);
        MachineResult::where('message_id', $message->id)->where('status', '!=', 'applied')->delete();

        $this->apply($machine, $message, new Reading($specimens), onlyUnapplied: true);

        return $message->refresh();
    }

    private function apply(Machine $machine, Message $message, Reading $reading, bool $onlyUnapplied = false): void
    {
        $mappings = Mapping::where('machine_id', $machine->id)->pluck('analyte_id', 'machine_code');
        $applied = $onlyUnapplied
            ? MachineResult::where('message_id', $message->id)->where('status', 'applied')->get()->map(fn ($r) => $r->machine_code.'|'.$r->sample_code)->all()
            : [];
        $codes = [];

        foreach ($reading->specimens as $specimen) {
            $sample = $this->sample($specimen['codes']);
            $sampleCode = $sample?->accession_no ?? ($specimen['codes'][0] ?? null);
            $codes[] = $sampleCode;
            $byWork = [];

            foreach ($specimen['results'] as $value) {
                $analyteId = $mappings[$value['code']] ?? ($value['alt'] !== null ? ($mappings[$value['alt']] ?? null) : null);
                $row = [
                    'message_id' => $message->id,
                    'sample_code' => $sampleCode !== null ? mb_substr($sampleCode, 0, 60) : null,
                    'machine_code' => mb_substr($value['code'], 0, 50),
                    'value' => mb_substr($value['value'], 0, 100),
                    'unit' => $value['unit'],
                    'machine_flag' => $value['flag'],
                    'analyte_id' => $analyteId,
                ];

                if (in_array($row['machine_code'].'|'.$row['sample_code'], $applied, true)) {
                    continue;
                }

                [$status, $work, $note] = match (true) {
                    $analyteId === null => ['unmapped', null, null],
                    $sample === null => ['no_sample', null, null],
                    default => $this->workFor($sample, (int) $analyteId),
                };

                if ($status === 'ready') {
                    $byWork[$work->id]['item'] = $work;
                    $byWork[$work->id]['rows'][] = $row + ['work_item_id' => $work->id];

                    continue;
                }

                MachineResult::create($row + ['status' => $status, 'work_item_id' => $work?->id, 'note' => $note]);
            }

            foreach ($byWork as $entry) {
                $this->save($machine, $entry['item'], $entry['rows']);
            }
        }

        $statuses = MachineResult::where('message_id', $message->id)->pluck('status');
        $status = match (true) {
            $statuses->isEmpty() => 'unmatched',
            $statuses->every(fn ($s) => $s === 'applied') => 'applied',
            $statuses->contains('applied') => 'partial',
            default => 'unmatched',
        };

        $message->forceFill([
            'status' => $status,
            'sample_codes' => mb_substr(implode(', ', array_unique(array_filter($codes))), 0, 255) ?: null,
            'note' => $statuses->isEmpty() ? 'The message had no results.' : null,
        ])->save();
    }

    /** @param list<string> $codes */
    private function sample(array $codes): ?Sample
    {
        foreach ($codes as $code) {
            if (($sample = Sample::where('accession_no', $code)->first()) !== null) {
                return $sample;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: ?WorkItem, 2: ?string} */
    private function workFor(Sample $sample, int $analyteId): array
    {
        $items = WorkItem::where('sample_id', $sample->id)
            ->where('status', '!=', WorkStatus::Cancelled->value)
            ->whereHas('labTest.analytes', fn ($q) => $q->whereKey($analyteId))
            ->get();

        if ($items->isEmpty()) {
            return ['no_test', null, null];
        }

        $ready = $items->first(fn (WorkItem $w): bool => $w->status === WorkStatus::Ready);

        if ($ready !== null) {
            return ['ready', $ready, null];
        }

        $first = $items->first();

        return $first->status === WorkStatus::AwaitingSample
            ? ['not_received', $first, null]
            : ['already_submitted', $first, $first->status->label()];
    }

    /** @param list<array<string, mixed>> $rows */
    private function save(Machine $machine, WorkItem $item, array $rows): void
    {
        $operator = User::where('is_active', true)->find($machine->user_id);
        $before = Auth::user();

        try {
            if ($operator === null) {
                throw new AnalyzerException("The lab account of {$machine->name} is missing or switched off.");
            }

            Auth::setUser($operator);

            DB::connection('clinic')->transaction(function () use ($item, $rows, $machine): void {
                $item = $item->fresh();
                // Keep what is already there, then put the machine's values on top.
                $values = Result::where('work_item_id', $item->id)->get()
                    ->mapWithKeys(fn (Result $r): array => [$r->analyte_id => ['value' => $r->value_text, 'comment' => $r->comment]])->all();

                foreach ($rows as $row) {
                    $values[$row['analyte_id']] = ['value' => $row['value'], 'comment' => $values[$row['analyte_id']]['comment'] ?? null];
                }

                app(EnterResults::class)->handle($item, $values, $item->lock_version, submit: false);

                foreach ($rows as $row) {
                    MachineResult::create($row + ['status' => 'applied']);
                }

                $this->audit->record('analyzer.results_saved', $item, [], [
                    'machine' => $machine->name, 'test' => $item->test_name, 'values' => count($rows),
                ]);
            });
        } catch (LabException|AnalyzerException $e) {
            foreach ($rows as $row) {
                MachineResult::create($row + ['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 255)]);
            }
        } catch (Throwable $e) {
            report($e);
            foreach ($rows as $row) {
                MachineResult::create($row + ['status' => 'failed', 'note' => 'Not saved: an unexpected error. The clinic\'s support has been told.']);
            }
        } finally {
            $before ? Auth::setUser($before) : Auth::forgetUser();
        }
    }
}
