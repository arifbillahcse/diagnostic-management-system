<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\FrontDesk\Actions\ImportCatalog;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Models\ServiceCategory;

class ImportTest extends FrontDeskTestCase
{
    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return $path;
    }

    public function test_a_price_list_from_excel_is_loaded_and_loaded_again_updates_it(): void
    {
        $file = $this->csv("\xEF\xBB\xBFCode,Name,Type,Category,Price,Room\nCBC,Complete blood count,lab_test,Haematology,400,Room 101\nUSG-WA,USG whole abdomen,imaging,Ultrasound,\"1,500\",Room 210\nECG,ECG,other,Cardiology,300,\n\n");

        $result = app(ImportCatalog::class)->services($file);

        $this->assertSame(['created' => 3, 'updated' => 0, 'unchanged' => 0, 'errors' => []], $result);
        $usg = Service::where('code', 'USG-WA')->sole();
        $this->assertSame('1500.00', $usg->price->toDecimal());
        $this->assertSame(ServiceType::Imaging, $usg->type);
        $this->assertSame('Room 210', ServiceCategory::where('name', 'Ultrasound')->value('room'));

        $again = $this->csv("code,name,type,category,price\nCBC,Complete blood count,lab_test,Haematology,450\nECG,ECG,other,Cardiology,300\n");
        $this->assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 1, 'errors' => []], app(ImportCatalog::class)->services($again));
        $this->assertSame('450.00', Service::where('code', 'CBC')->value('price')->toDecimal());
        $this->assertTrue(AuditLog::where('action', 'service.updated')->exists(), 'The old price stays in the audit log');
    }

    public function test_one_bad_row_saves_nothing_and_every_problem_is_listed(): void
    {
        $file = $this->csv("code,name,type,category,price\nCBC,Complete blood count,lab_test,Haematology,400\nCBC,Again,lab_test,Haematology,400\nBAD CODE,X,lab_test,,400\nPKG,Package,package,,1000\nRBS,Sugar,lab_test,,four hundred\n");

        $result = app(ImportCatalog::class)->services($file);

        $this->assertSame(0, Service::count());
        $this->assertCount(4, $result['errors']);
        $this->assertStringContainsString('Row 3: the code CBC is also on row 2', implode("\n", $result['errors']));
        $this->assertStringContainsString('Row 6: the price', implode("\n", $result['errors']));
    }

    public function test_a_trial_run_counts_but_keeps_nothing(): void
    {
        $file = $this->csv("code,name,type,category,price\nCBC,Complete blood count,lab_test,Haematology,400\n");

        $this->assertSame(1, app(ImportCatalog::class)->services($file, dryRun: true)['created']);
        $this->assertSame(0, Service::count());
        $this->assertSame(0, ServiceCategory::count());
    }

    public function test_referral_doctors_and_the_command_line(): void
    {
        $file = $this->csv("code,name,degree,phone\nD01,Dr. Kamrul Islam,\"MBBS, MS\",01711000001\nD02,Dr. Nasrin Akter,MBBS,\n");
        $clinic = app(ClinicContext::class)->require();

        $this->artisan('frontdesk:import', ['clinic' => $clinic->id, 'what' => 'doctors', 'file' => $file, '--dry-run' => true])
            ->expectsOutputToContain('Trial run, nothing saved: 2 new')->assertSuccessful();
        $this->artisan('frontdesk:import', ['clinic' => $clinic->id, 'what' => 'doctors', 'file' => $file])->assertSuccessful();
        $this->assertSame('MBBS, MS', ReferralDoctor::where('code', 'D01')->value('degree'));

        $bad = $this->csv("name,price\nX,1\n");
        $this->artisan('frontdesk:import', ['clinic' => $clinic->id, 'what' => 'services', 'file' => $bad])
            ->expectsOutputToContain('Missing: code, type, category')->assertFailed();
    }

    public function test_importing_needs_the_catalog_permission(): void
    {
        $this->actingAs($this->staff('receptionist'));

        $this->expectException(AuthorizationException::class);
        app(ImportCatalog::class)->services($this->csv("code,name,type,category,price\nX,Y,other,,1\n"));
    }
}
