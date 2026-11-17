<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Core\Branches\CurrentBranch;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Branches in a list. With multi-branch on:
 *
 * - scope() always limits the list to the branches the user works in (use
 *   it with ->modifyQueryUsing(); it applies even when the filter is hidden);
 * - make() is the filter that narrows to one of them, starting on the
 *   current branch; "All my branches" shows every one of them. It is only
 *   shown to users with more than one branch.
 *
 * With multi-branch off both do nothing.
 */
final class BranchFilter
{
    /**
     * @param  string|list<string>  $column  the branch column, "relation.branch_id" through a relation, or
     *                                       several columns, any of which may match (ordered here OR tested here)
     */
    public static function make(string|array $column = 'branch_id'): SelectFilter
    {
        $branches = app(CurrentBranch::class);

        return SelectFilter::make('branch')
            ->label('Branch')
            ->placeholder('All my branches')
            ->options(fn (): array => $branches->allowed()->pluck('name', 'id')->all())
            ->default(fn (): ?int => $branches->multi() ? $branches->get()->id : null)
            ->visible(fn (): bool => $branches->multi())
            ->query(function (Builder $query, array $data) use ($branches, $column): Builder {
                $scope = $branches->scope();

                if ($scope === null) {
                    return $query;
                }

                $chosen = filled($data['value'] ?? null) && in_array((int) $data['value'], $scope, true) ? [(int) $data['value']] : $scope;

                return self::where($query, $column, $chosen);
            });
    }

    /** The always-on limit to the user's branches, for ->modifyQueryUsing(). */
    /** @param string|list<string> $column */
    public static function scope(string|array $column = 'branch_id'): \Closure
    {
        return function (Builder $query) use ($column): Builder {
            $scope = app(CurrentBranch::class)->scope();

            return $scope === null ? $query : self::where($query, $column, $scope);
        };
    }

    /** @param string|list<string> $column @param list<int> $ids */
    private static function where(Builder $query, string|array $column, array $ids): Builder
    {
        if (is_array($column)) {
            return $query->where(function (Builder $q) use ($column, $ids): void {
                foreach ($column as $one) {
                    $q->orWhere(fn (Builder $inner) => self::where($inner, $one, $ids));
                }
            });
        }

        if (! str_contains($column, '.')) {
            return $query->whereIn($query->qualifyColumn($column), $ids);
        }

        [$relation, $field] = explode('.', $column, 2);

        return $query->whereHas($relation, fn (Builder $q) => $q->whereIn($q->qualifyColumn($field), $ids));
    }
}
