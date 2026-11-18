<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Models\ServiceCategory;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\FrontDeskException;

/**
 * Loads a clinic's test list or referral doctors from a CSV file (saved
 * from Excel), so a new clinic is not typed in by hand.
 *
 * All or nothing: every row is checked first, and nothing is saved when
 * any row is wrong. A row whose code already exists updates that record
 * (each change is audited by the model, so old prices stay in the log;
 * invoices already issued keep their own copied prices).
 */
final class ImportCatalog
{
    public const SERVICE_COLUMNS = ['code', 'name', 'type', 'category', 'price'];

    public const DOCTOR_COLUMNS = ['code', 'name'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{created: int, updated: int, unchanged: int, errors: list<string>} */
    public function services(string $path, bool $dryRun = false): array
    {
        Gate::authorize(Permissions::CATALOG_MANAGE);

        [$rows, $errors] = $this->read($path, self::SERVICE_COLUMNS, ['room', 'active']);
        $types = ['lab_test' => ServiceType::LabTest, 'lab' => ServiceType::LabTest, 'imaging' => ServiceType::Imaging, 'other' => ServiceType::Other];
        $seen = [];

        foreach ($rows as $line => $row) {
            $code = strtoupper($row['code']);
            if (! preg_match('/^[A-Z0-9][A-Z0-9_\-]{0,29}$/', $code)) {
                $errors[] = "Row {$line}: the code \"{$row['code']}\" may have letters, numbers, - and _ only (up to 30).";
            }
            if (isset($seen[$code])) {
                $errors[] = "Row {$line}: the code {$code} is also on row {$seen[$code]}.";
            }
            $seen[$code] = $line;
            if ($row['name'] === '' || mb_strlen($row['name']) > 255) {
                $errors[] = "Row {$line}: the name is empty or too long.";
            }
            if (! isset($types[strtolower($row['type'])])) {
                $errors[] = "Row {$line}: the type must be lab_test, imaging, or other (packages are made on screen).";
            }
            if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', str_replace(',', '', $row['price']))) {
                $errors[] = "Row {$line}: the price \"{$row['price']}\" is not an amount such as 400 or 1250.50.";
            }
        }

        if ($errors !== []) {
            return ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => $errors];
        }

        return $this->save($dryRun, function () use ($rows, $types): array {
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

            foreach ($rows as $row) {
                $category = $row['category'] === '' ? null : ServiceCategory::firstOrCreate(['name' => $row['category']]);
                if ($category && ($row['room'] ?? '') !== '' && $category->room !== $row['room']) {
                    $category->forceFill(['room' => $row['room']])->save();
                }

                $values = [
                    'name' => $row['name'],
                    'type' => $types[strtolower($row['type'])],
                    'category_id' => $category?->id,
                    'price' => Money::fromDecimal(str_replace(',', '', $row['price']))->toDecimal(),
                    'is_active' => ! in_array(strtolower($row['active'] ?? 'yes'), ['no', '0', 'false'], true),
                ];

                $counts[$this->upsert(Service::withTrashed()->where('code', strtoupper($row['code']))->first(), Service::class, ['code' => strtoupper($row['code'])], $values)]++;
            }

            return $counts;
        }, 'catalog.imported');
    }

    /** @return array{created: int, updated: int, unchanged: int, errors: list<string>} */
    public function doctors(string $path, bool $dryRun = false): array
    {
        Gate::authorize(Permissions::CATALOG_MANAGE);

        [$rows, $errors] = $this->read($path, self::DOCTOR_COLUMNS, ['degree', 'phone']);
        $seen = [];

        foreach ($rows as $line => $row) {
            $code = strtoupper($row['code']);
            if ($code === '' || mb_strlen($code) > 20) {
                $errors[] = "Row {$line}: the code is empty or longer than 20.";
            }
            if (isset($seen[$code])) {
                $errors[] = "Row {$line}: the code {$code} is also on row {$seen[$code]}.";
            }
            $seen[$code] = $line;
            if ($row['name'] === '') {
                $errors[] = "Row {$line}: the name is empty.";
            }
        }

        if ($errors !== []) {
            return ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => $errors];
        }

        return $this->save($dryRun, function () use ($rows): array {
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

            foreach ($rows as $row) {
                $values = ['name' => $row['name'], 'degree' => ($row['degree'] ?? '') ?: null, 'phone' => ($row['phone'] ?? '') ?: null, 'is_active' => true];
                $counts[$this->upsert(ReferralDoctor::where('code', strtoupper($row['code']))->first(), ReferralDoctor::class, ['code' => strtoupper($row['code'])], $values)]++;
            }

            return $counts;
        }, 'doctors.imported');
    }

    /** @return string created, updated or unchanged */
    private function upsert(?object $existing, string $class, array $key, array $values): string
    {
        if ($existing === null) {
            $class::create($key + $values);

            return 'created';
        }

        if (method_exists($existing, 'trashed') && $existing->trashed()) {
            $existing->restore();
        }

        $existing->fill($values);

        // "300" and "300.00", or 1 and true, are the same value: not a change.
        $changed = array_filter(array_keys($existing->getDirty()), fn (string $k): bool => ! self::same($existing->getOriginal($k), $existing->{$k}));

        if ($changed === []) {
            $existing->syncOriginal();

            return 'unchanged';
        }

        $existing->save();

        return 'updated';
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return match (true) {
            $a instanceof Money && $b instanceof Money => $a->equals($b),
            $a instanceof \BackedEnum || $b instanceof \BackedEnum => ($a instanceof \BackedEnum ? $a->value : $a) === ($b instanceof \BackedEnum ? $b->value : $b),
            is_bool($a) || is_bool($b) => (bool) $a === (bool) $b,
            default => (string) $a === (string) $b,
        };
    }

    /** @return array{created: int, updated: int, unchanged: int, errors: list<string>} */
    private function save(bool $dryRun, \Closure $work, string $action): array
    {
        $result = null;

        try {
            DB::connection('clinic')->transaction(function () use ($work, $dryRun, $action, &$result): void {
                $result = $work() + ['errors' => []];
                $this->audit->record($action, null, [], array_diff_key($result, ['errors' => 1]), ['dry_run' => $dryRun]);

                if ($dryRun) {
                    throw new DryRunFinished;
                }
            });
        } catch (DryRunFinished) {
            // Nothing was kept: it was a trial run.
        }

        return $result;
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $optional
     * @return array{0: array<int, array<string, string>>, 1: list<string>} rows by line number, and errors
     */
    private function read(string $path, array $required, array $optional): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new FrontDeskException('The file cannot be read.');
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');

        if ($header === false) {
            fclose($handle);
            throw new FrontDeskException('The file is empty.');
        }

        // Excel adds a byte-order mark to UTF-8 files.
        $header = array_map(fn ($h): string => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $missing = array_diff($required, $header);

        if ($missing !== []) {
            fclose($handle);
            throw new FrontDeskException('The first row must name the columns. Missing: '.implode(', ', $missing).'.');
        }

        $rows = [];
        $errors = [];
        $line = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue; // empty line
            }

            if (count($rows) >= 5000) {
                $errors[] = 'The file has more than 5000 rows. Split it.';
                break;
            }

            $row = [];
            foreach ([...$required, ...$optional] as $column) {
                $index = array_search($column, $header, true);
                $row[$column] = $index === false ? '' : trim((string) ($cells[$index] ?? ''));
            }
            $rows[$line] = $row;
        }

        fclose($handle);

        if ($rows === [] && $errors === []) {
            $errors[] = 'The file has no rows under the column names.';
        }

        return [$rows, $errors];
    }
}
