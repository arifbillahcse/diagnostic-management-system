<?php

declare(strict_types=1);

namespace App\Core\Documents;

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

    /** @return array{name: ?string, address: ?string, phone: ?string, email: ?string, header: ?string, logo: ?string} */
    public function data(): array
    {
        return [
            'name' => $this->settings->get('clinic.name'),
            'address' => $this->settings->get('clinic.address'),
            'phone' => $this->settings->get('clinic.phone'),
            'email' => $this->settings->get('clinic.email'),
            'header' => $this->settings->get('clinic.report_header'),
            'logo' => $this->files->dataUri($this->settings->get('clinic.logo_path')),
        ];
    }
}
