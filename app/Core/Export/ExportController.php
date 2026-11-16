<?php

declare(strict_types=1);

namespace App\Core\Export;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\CorePermissions;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

final class ExportController
{
    public function __construct(
        private readonly DataExporter $exporter,
        private readonly AuditLogger $audit,
        private readonly ClinicContext $clinics,
    ) {}

    /** One table as a CSV file. */
    public function table(string $table): StreamedResponse
    {
        Gate::authorize(CorePermissions::EXPORT_RUN);
        abort_unless(array_key_exists($table, $this->exporter->datasets()), 404);

        $this->audit->record('export.downloaded', null, [], [], ['table' => $table, 'rows' => $this->exporter->count($table)]);

        return response()->streamDownload(function () use ($table): void {
            $out = fopen('php://output', 'w');
            $this->exporter->write($table, $out);
            fclose($out);
        }, "{$table}-".now($this->clinics->require()->timezone)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Every table, as CSV files inside one zip. */
    public function all(): BinaryFileResponse
    {
        Gate::authorize(CorePermissions::EXPORT_RUN);

        $work = storage_path('framework/cache/exports/'.Str::uuid());
        File::ensureDirectoryExists($work);

        $zip = new ZipArchive;
        $zipPath = "{$work}/export.zip";
        $zip->open($zipPath, ZipArchive::CREATE);
        $rows = [];

        foreach (array_keys($this->exporter->datasets()) as $table) {
            $out = fopen("{$work}/{$table}.csv", 'w');
            $rows[$table] = $this->exporter->write($table, $out);
            fclose($out);
            $zip->addFile("{$work}/{$table}.csv", "{$table}.csv");
        }

        $zip->close();

        foreach (array_keys($rows) as $table) {
            File::delete("{$work}/{$table}.csv");
        }

        $this->audit->record('export.downloaded', null, [], [], ['table' => 'all', 'rows' => array_sum($rows), 'tables' => count($rows)]);

        $name = Str::slug($this->clinics->require()->name).'-export-'.now($this->clinics->require()->timezone)->format('Y-m-d').'.zip';

        // The zip holds patient data: it is removed as soon as it is sent.
        return response()->download($zipPath, $name)->deleteFileAfterSend(true);
    }
}
