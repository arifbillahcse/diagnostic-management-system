<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\Pages;

use App\Core\Branches\Actions\SaveBranch;
use App\Core\Tenancy\PlanLimitException;
use App\Filament\Resources\Branches\BranchResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageBranches extends ManageRecords
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New branch')
                ->using(fn (array $data, Action $action) => self::guard($action, fn () => app(SaveBranch::class)->create($data))),
        ];
    }

    /** A plan limit becomes a message, and the form stays open. */
    public static function guard(Action $action, \Closure $work): mixed
    {
        try {
            return $work();
        } catch (PlanLimitException $e) {
            Notification::make()->danger()->title('Not allowed on your plan')->body($e->getMessage())->persistent()->send();
            $action->halt();
        }

        return null;
    }
}
