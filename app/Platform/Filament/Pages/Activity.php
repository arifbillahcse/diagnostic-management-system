<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/** Everything platform users did, newest first. */
class Activity extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Activity';

    protected static ?string $slug = 'activity';

    protected string $view = 'platform.activity';

    /** @return list<object> */
    public function getRowsProperty(): array
    {
        return DB::connection('control')->table('platform_audit')
            ->leftJoin('platform_users', 'platform_users.id', '=', 'platform_audit.platform_user_id')
            ->leftJoin('clinics', 'clinics.id', '=', 'platform_audit.clinic_id')
            ->orderByDesc('platform_audit.id')->limit(200)
            ->get(['platform_audit.created_at', 'platform_audit.action', 'platform_audit.details', 'platform_audit.ip', 'platform_users.name as who', 'clinics.name as clinic'])
            ->all();
    }
}
