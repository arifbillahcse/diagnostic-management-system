<?php

declare(strict_types=1);

namespace App\Core\Numbering\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\CorePermissions;
use App\Core\Branches\Branch;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\NumberGenerator;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Changes how the clinic's numbers look, for example from
 * "INV-MALIBUGH-2611-00034" to "2611-00035".
 *
 * Numbers already issued never change. A new format applies from the next
 * number. When the new format restarts its sequence at the same moments as
 * the old one (both monthly, say), the new sequence carries on from the old
 * one instead of going back to 1.
 */
final class ChangeNumberFormats
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
        private readonly ClinicContext $clinics,
        private readonly NumberGenerator $numbers,
    ) {}

    /**
     * @param  array<string, string>  $patterns  number type => new pattern (blank goes back to the built-in format)
     * @return list<string> the types whose format changed
     *
     * @throws InvalidArgumentException when a pattern is not usable; nothing is changed then
     */
    public function handle(array $patterns): array
    {
        Gate::authorize(CorePermissions::SETTINGS_MANAGE);

        $types = NumberGenerator::types();
        $changes = [];

        foreach ($patterns as $type => $pattern) {
            if (! isset($types[$type])) {
                throw new InvalidArgumentException("There is no number type called [{$type}].");
            }

            $pattern = trim((string) $pattern);
            $new = $pattern === '' ? $types[$type]['default'] : $pattern;

            try {
                new NumberFormat($new);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException("{$types[$type]['label']}: the format needs exactly one counter such as {00001}.");
            }

            if (preg_match('/[^A-Za-z0-9{}\-\/_. ]/', $new)) {
                throw new InvalidArgumentException("{$types[$type]['label']}: use only letters, digits, and - / _ . in a format.");
            }

            $old = $this->numbers->format($type)->pattern;

            if ($old !== $new) {
                $changes[$type] = ['old' => $old, 'new' => $new, 'stored' => $new === $types[$type]['default'] ? null : $new];
            }
        }

        if ($changes === []) {
            return [];
        }

        DB::connection('clinic')->transaction(function () use ($changes): void {
            $before = $after = [];

            foreach ($changes as $type => $change) {
                $this->settings->set("numbering.{$type}", $change['stored']);
                $this->carryOn($type, new NumberFormat($change['old']), new NumberFormat($change['new']));

                $before["numbering.{$type}"] = $change['old'];
                $after["numbering.{$type}"] = $change['new'];
            }

            $this->audit->record('numbering.changed', null, $before, $after);
        });

        return array_keys($changes);
    }

    /** Start the new counter where the old one is, when both restart together. */
    private function carryOn(string $type, NumberFormat $old, NumberFormat $new): void
    {
        $date = now()->setTimezone($this->clinics->require()->timezone);

        if ($old->period($date) !== $new->period($date)) {
            return;
        }

        $period = $new->period($date);
        $db = DB::connection('clinic')->table('counters');
        $next = [];

        foreach ((clone $db)->where('counter_key', $type)->where('period', $period)->get() as $row) {
            $targets = match (true) {
                $new->usesBranch() && ! $old->usesBranch() => Branch::pluck('id')->all(), // one counter per branch from now on
                $new->usesBranch() => [(int) $row->branch_id],
                default => [0],                                                           // one counter for the whole clinic
            };

            foreach ($targets as $branchId) {
                $next[$branchId] = max($next[$branchId] ?? 0, (int) $row->next_value);
            }
        }

        foreach ($next as $branchId => $value) {
            $where = ['counter_key' => $type, 'branch_id' => $branchId, 'period' => $period];
            $existing = (int) (clone $db)->where($where)->value('next_value');

            if ($value > $existing) {
                $now = now();
                (clone $db)->upsert([$where + ['next_value' => $value, 'created_at' => $now, 'updated_at' => $now]], ['counter_key', 'branch_id', 'period'], ['next_value', 'updated_at']);
            }
        }
    }
}
