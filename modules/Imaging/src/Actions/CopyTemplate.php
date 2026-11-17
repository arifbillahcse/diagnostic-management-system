<?php

declare(strict_types=1);

namespace Modules\Imaging\Actions;

use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Service;
use Modules\Imaging\Models\Template;
use Modules\Imaging\Permissions;
use Modules\Imaging\Support\ImagingException;

/** Copies a report template to another imaging test (the copy is audited as a new template). */
final class CopyTemplate
{
    public function handle(Template $template, int $serviceId, string $name): Template
    {
        Gate::authorize(Permissions::TEMPLATES_MANAGE);

        $name = trim($name);
        $service = Service::find($serviceId);

        if ($service === null || $service->type !== ServiceType::Imaging) {
            throw new ImagingException('Choose an imaging test.');
        }
        if ($name === '') {
            throw new ImagingException('Give the copy a name.');
        }
        if (Template::where('service_id', $serviceId)->where('name', $name)->exists()) {
            throw new ImagingException("{$service->name} already has a template called \"{$name}\".");
        }

        return Template::create($template->only(['sex', 'findings', 'impression', 'advice', 'sort_order']) + [
            'service_id' => $serviceId, 'name' => $name, 'is_active' => true,
        ]);
    }
}
