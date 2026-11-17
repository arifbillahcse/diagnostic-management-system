<?php

declare(strict_types=1);

namespace Modules\Analyzers;

/**
 * Permission keys of the lab machine integration module.
 */
final class Permissions
{
    /** Add machines, give and replace their keys, and map their test codes. */
    public const MACHINES_MANAGE = 'analyzers.machines.manage';

    /** See the machine inbox and match results that did not find their sample. */
    public const INBOX_RESOLVE = 'analyzers.inbox.resolve';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::MACHINES_MANAGE => 'Add lab machines, give their keys, and map their test codes',
            self::INBOX_RESOLVE => 'See machine results and match those that did not find their sample',
        ];
    }
}
