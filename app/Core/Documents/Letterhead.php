<?php

declare(strict_types=1);

namespace App\Core\Documents;

use App\Core\Branches\Branch;
use App\Core\Settings\Settings;

/**
 * The clinic's name, contact details, and logo, as printed at the top of
 * documents.
 */
final class Letterhead
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ClinicFiles $files,
    ) {}

    /**
     * The clinic's header for printed papers. With a branch given, that
     * branch's own address and phone replace the clinic's where the branch
     * has them, so each branch prints its own.
     *
     * @return array{name: ?string, address: ?string, phone: ?string, email: ?string, header: ?string, logo: ?string, branch: ?string}
     */
    public function data(?Branch $branch = null): array
    {
        return [
            'name' => $this->settings->get('clinic.name'),
            'address' => filled($branch?->address) ? $branch->address : $this->settings->get('clinic.address'),
            'phone' => filled($branch?->phone) ? $branch->phone : $this->settings->get('clinic.phone'),
            'branch' => $branch !== null && Branch::where('is_active', true)->count() > 1 ? $branch->name : null,
            'email' => $this->settings->get('clinic.email'),
            'header' => $this->settings->get('clinic.report_header'),
            'logo' => $this->files->dataUri($this->settings->get('clinic.logo_path')),
        ];
    }
}
