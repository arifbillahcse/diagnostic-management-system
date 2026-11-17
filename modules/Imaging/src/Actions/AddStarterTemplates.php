<?php

declare(strict_types=1);

namespace Modules\Imaging\Actions;

use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Service;
use Modules\Imaging\Models\Phrase;
use Modules\Imaging\Models\Template;
use Modules\Imaging\Permissions;
use Modules\Imaging\Support\ImagingException;
use Modules\Imaging\Support\StarterTemplates;

/** Copies one set of example templates onto an imaging service, and adds the example phrases once. */
final class AddStarterTemplates
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return int how many templates were added (existing names are skipped) */
    public function handle(Service $service, string $set): int
    {
        Gate::authorize(Permissions::TEMPLATES_MANAGE);

        $sets = StarterTemplates::sets();

        if (! isset($sets[$set])) {
            throw new ImagingException('Choose a set of example templates.');
        }
        if ($service->type !== ServiceType::Imaging) {
            throw new ImagingException("{$service->name} is not an imaging service. Set its type to Imaging in the catalog first.");
        }

        return DB::connection('clinic')->transaction(function () use ($service, $set, $sets): int {
            $added = 0;

            foreach ($sets[$set]['templates'] as $i => $template) {
                if (Template::where('service_id', $service->id)->where('name', $template['name'])->exists()) {
                    continue;
                }

                Template::create($template + ['service_id' => $service->id, 'sort_order' => $i]);
                $added++;
            }

            foreach (StarterTemplates::phrases() as $phrase) {
                Phrase::firstOrCreate(['title' => $phrase['title']], ['body' => $phrase['body']]);
            }

            $this->audit->record('imaging.starter_templates_added', $service, [], ['set' => $sets[$set]['label'], 'added' => $added]);

            return $added;
        });
    }
}
