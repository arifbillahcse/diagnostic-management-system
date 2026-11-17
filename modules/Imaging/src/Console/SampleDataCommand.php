<?php

declare(strict_types=1);

namespace Modules\Imaging\Console;

use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\Service;
use Modules\Imaging\Actions\AddStarterTemplates;
use Modules\Imaging\Actions\WriteReport;
use Modules\Imaging\Models\Study;
use Modules\Imaging\Models\Template;
use Modules\Imaging\Support\StudyPlanner;
use RuntimeException;
use Throwable;

/**
 * Sample imaging for trying the module: the example templates on the
 * sample ultrasound and X-ray tests, and a few reports written and signed.
 */
final class SampleDataCommand extends Command
{
    protected $signature = 'imaging:sample-data
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {--force : Do not ask for confirmation (and allow it on a production server)}';

    protected $description = 'Load example imaging templates and a few signed imaging reports for trying the imaging module';

    /** Example set per imaging test, matched by code, then by words in the name. */
    private const SETS = [
        'USG-ABD' => 'usg-whole-abdomen', 'XRAY-CHEST' => 'xray-chest', 'ECG' => 'ecg',
        'whole abdomen' => 'usg-whole-abdomen', 'lower abdomen' => 'usg-lower-abdomen', 'kub' => 'usg-kub',
        'pregnan' => 'usg-pregnancy', 'chest' => 'xray-chest', 'ecg' => 'ecg',
    ];

    public function handle(ClinicManager $manager): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('This server is in production. Add --force only for a clinic that will never hold real data.');

            return self::FAILURE;
        }

        $clinic = Clinic::find((int) $this->argument('clinic'));

        if ($clinic === null) {
            $this->error('There is no clinic with that ID. Run clinic:list to see the IDs.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Load sample imaging into \"{$clinic->name}\"? It cannot be removed afterwards.")) {
            return self::FAILURE;
        }

        try {
            [$templates, $signed] = $manager->run($clinic, fn (): array => $this->load());
        } catch (Throwable $e) {
            $this->error("Sample imaging was not loaded: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            Auth::forgetUser();
        }

        $this->info("Sample imaging loaded into \"{$clinic->name}\": {$templates} templates, {$signed} signed report(s).");
        $this->warn('The templates are examples. The reporting doctor must review them before real use.');

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} */
    private function load(): array
    {
        if (! app(ModuleState::class)->isEnabled('imaging')) {
            throw new RuntimeException('The imaging module is switched off for this clinic. Turn it on from the Modules page first.');
        }
        if (Template::query()->exists()) {
            throw new RuntimeException('This clinic already has imaging templates. Sample imaging is only for a clinic without any.');
        }

        $owner = User::whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->firstOrFail();
        Auth::setUser($owner);

        $services = Service::where('type', ServiceType::Imaging->value)->where('is_active', true)->get();

        if ($services->isEmpty()) {
            throw new RuntimeException('There is no imaging test in the catalog. Run laboratory:sample-data first, or add one.');
        }

        $added = 0;
        foreach ($services as $service) {
            $set = $this->setFor($service);
            if ($set !== null) {
                $added += app(AddStarterTemplates::class)->handle($service, $set);
            }
        }

        // Bills saved before imaging was on get their studies, then a few are reported.
        Order::where('ordered_at', '>=', now()->subDays(14))->orderBy('id')->each(fn (Order $o) => app(StudyPlanner::class)->planFor($o));

        $signed = 0;
        foreach (Study::where('status', 'pending')->orderBy('id')->limit(3)->get() as $study) {
            $template = Template::where('service_id', $study->service_id)->orderBy('sort_order')->first();
            if ($template === null) {
                continue;
            }
            app(WriteReport::class)->sign($study, [
                'findings' => $template->findings, 'impression' => $template->impression, 'advice' => $template->advice, 'template_id' => $template->id,
            ], $study->lock_version);
            $signed++;
        }

        return [$added, $signed];
    }

    private function setFor(Service $service): ?string
    {
        foreach (self::SETS as $match => $set) {
            if (strcasecmp($service->code, $match) === 0 || stripos($service->name, $match) !== false) {
                return $set;
            }
        }

        return null;
    }
}
