<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit\AuditLog;
use App\Core\Branches\Branch;
use App\Core\Export\DataExporter;
use App\Core\Modules\ModuleManager;
use App\Core\Patients\Actions\SavePatient;
use App\Core\Tenancy\ClinicManager;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

final class DataExportTest extends TestCase
{
    private const BASE = 'http://alpha.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useClinic('alpha');
        $this->actingAs($this->owner());
    }

    private function patient(string $name, string $phone = '01711000000'): void
    {
        app(SavePatient::class)->register(['name' => $name, 'sex' => 'male', 'date_of_birth' => '1990-01-01', 'phone' => $phone], Branch::first());
    }

    /** @return list<list<string>> */
    private function csv(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'the file starts with a UTF-8 mark so Excel reads it correctly');
        $lines = array_filter(explode("\n", substr($content, 3)), fn (string $line): bool => $line !== '');

        return array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values($lines));
    }

    public function test_a_table_downloads_as_csv_with_a_heading_row(): void
    {
        $this->patient('Rahim Uddin');
        $this->patient('Salma, "Shimu" Begum');

        $response = $this->signIn($this->owner())->get(self::BASE.'/export/patients.csv');

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $rows = $this->csv($response->streamedContent());

        $this->assertContains('patient_code', $rows[0]);
        $this->assertCount(3, $rows);
        $name = array_search('name', $rows[0], true);
        $this->assertSame(['Rahim Uddin', 'Salma, "Shimu" Begum'], [$rows[1][$name], $rows[2][$name]], 'commas and quotes survive');

        $entry = AuditLog::where('action', 'export.downloaded')->sole();
        $this->assertEquals(['table' => 'patients', 'rows' => 2], $entry->context);
    }

    public function test_passwords_are_never_exported(): void
    {
        $rows = $this->csv($this->signIn($this->owner())->get(self::BASE.'/export/users.csv')->streamedContent());

        $this->assertContains('email', $rows[0]);
        $this->assertNotContains('password', $rows[0]);
        $this->assertNotContains('remember_token', $rows[0]);
        $this->assertStringNotContainsString('$2y$', json_encode($rows));
    }

    public function test_text_that_a_spreadsheet_would_run_as_a_formula_is_made_harmless(): void
    {
        $this->patient('=HYPERLINK("http://evil.test","Click")', '+8801711000000');
        $this->patient('-Minus Name', '01811000000');

        $rows = $this->csv($this->signIn($this->owner())->get(self::BASE.'/export/patients.csv')->streamedContent());
        $name = array_search('name', $rows[0], true);
        $phone = array_search('phone', $rows[0], true);

        $this->assertSame("'=HYPERLINK(\"http://evil.test\",\"Click\")", $rows[1][$name]);
        $this->assertSame("'+8801711000000", $rows[1][$phone]);
        $this->assertSame("'-Minus Name", $rows[2][$name]);
    }

    public function test_only_known_tables_can_be_exported(): void
    {
        foreach (['migrations', 'settings', 'role_permissions', 'no_such_table'] as $table) {
            $this->signIn($this->owner())->get(self::BASE."/export/{$table}.csv")->assertNotFound();
        }

        $this->expectException(InvalidArgumentException::class);
        app(DataExporter::class)->count('settings');
    }

    public function test_the_list_covers_core_and_every_installed_module_even_when_switched_off(): void
    {
        $tables = fn (): array => array_keys(app(DataExporter::class)->datasets());

        $this->assertContains('patients', $tables());
        $this->assertContains('fd_invoices', $tables());
        $this->assertContains('lab_results', $tables());
        $this->assertNotContains('fin_expenses', $tables(), 'finance has never been switched on here');

        app(ModuleManager::class)->enable('finance');
        $this->assertContains('fin_expenses', $tables());

        $this->disableModule('finance');
        $this->assertContains('fin_expenses', $tables(), 'a switched-off module\'s data still belongs to the clinic');
    }

    public function test_everything_downloads_as_one_zip_and_the_copy_on_the_server_is_removed(): void
    {
        $this->patient('Rahim Uddin');

        $response = $this->signIn($this->owner())->get(self::BASE.'/export/all.zip');
        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertEqualsCanonicalizing(array_map(fn (string $t): string => "{$t}.csv", array_keys(app(DataExporter::class)->datasets())), $names);
        $this->assertStringContainsString('Rahim Uddin', $zip->getFromName('patients.csv'));
        $zip->close();

        $this->assertStringContainsString('alpha-diagnostics-export-', $response->headers->get('Content-Disposition'));
        $this->assertSame(['export.zip'], array_map('basename', glob(dirname($path).'/*')), 'the loose CSV files are gone; the zip is deleted after sending');
        $this->assertSame('all', AuditLog::where('action', 'export.downloaded')->sole()->context['table']);

        @unlink($path);
        @rmdir(dirname($path));
    }

    public function test_export_needs_the_permission_and_a_signed_in_user(): void
    {
        $this->signIn($this->userWithRoles('accountant'))->get(self::BASE.'/export/patients.csv')->assertForbidden();
        $this->signIn($this->userWithRoles('accountant'))->get(self::BASE.'/export/all.zip')->assertForbidden();
        $this->signIn($this->userWithRoles('accountant'))->get(self::BASE.'/data-export')->assertForbidden();

        $this->signIn($this->owner())->get(self::BASE.'/data-export')->assertOk()->assertSee('Download everything (zip)')->assertSee('Patients');

        auth()->logout();
        $this->flushSession();
        $this->get(self::BASE.'/export/patients.csv')->assertRedirect('/login');
    }

    public function test_an_export_holds_only_the_current_clinics_data(): void
    {
        $this->patient('Only At Alpha');

        $beta = $this->createClinic('beta');
        app(ClinicManager::class)->activate($beta);

        $content = $this->signIn($this->owner())->get('http://beta.test/export/patients.csv')->streamedContent();

        $this->assertStringNotContainsString('Only At Alpha', $content);
        $this->assertCount(1, $this->csv($content), 'just the heading row');
    }
}
