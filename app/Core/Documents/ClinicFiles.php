<?php

declare(strict_types=1);

namespace App\Core\Documents;

use App\Core\Tenancy\ClinicContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where a clinic's uploaded files live: a private disk, in a folder of the
 * clinic's own. Files here are never served by a public address.
 */
final class ClinicFiles
{
    public const DISK = 'local';

    public function __construct(private readonly ClinicContext $clinics) {}

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /** Folder for the current clinic, for example "clinics/12/branding". */
    public function directory(string $sub): string
    {
        return 'clinics/'.$this->clinics->require()->id.'/'.trim($sub, '/');
    }

    /**
     * The file as a data URI for embedding in a document, or null when it
     * is missing or is not inside the current clinic's folder.
     */
    public function dataUri(?string $path): ?string
    {
        if (blank($path) || ! str_starts_with($path, 'clinics/'.$this->clinics->require()->id.'/') || str_contains($path, '..')) {
            return null;
        }

        if (! $this->disk()->exists($path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };

        return $mime === null ? null : "data:{$mime};base64,".base64_encode((string) $this->disk()->get($path));
    }
}
