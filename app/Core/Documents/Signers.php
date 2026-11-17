<?php

declare(strict_types=1);

namespace App\Core\Documents;

use App\Core\Auth\User;

/**
 * What to print for the people who signed a report: their printed name,
 * the lines under it, and their signature image (or the account name when
 * no signatory is set up).
 */
final class Signers
{
    public function __construct(private readonly ClinicFiles $files) {}

    /**
     * @param  iterable<int>  $userIds
     * @return list<array{user_id: int, name: string, lines: list<string>, signature: ?string}>
     */
    public function for(iterable $userIds): array
    {
        $ids = array_values(array_unique(array_filter(is_array($userIds) ? $userIds : iterator_to_array($userIds))));

        if ($ids === []) {
            return [];
        }

        $signatories = Signatory::whereIn('user_id', $ids)->get()->keyBy('user_id');
        $users = User::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(function (int $id) use ($signatories, $users): ?array {
            $user = $users->get($id);

            if ($user === null) {
                return null;
            }

            $signatory = $signatories->get($id);

            return [
                'user_id' => $id,
                'name' => $signatory?->display_name ?? $user->name,
                'lines' => $signatory?->qualificationLines() ?? [],
                'signature' => $this->files->dataUri($signatory?->signature_path),
            ];
        }, $ids)));
    }
}
