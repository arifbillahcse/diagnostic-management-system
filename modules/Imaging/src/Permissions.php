<?php

declare(strict_types=1);

namespace Modules\Imaging;

/**
 * Permission and setting keys of the imaging module.
 */
final class Permissions
{
    /** Create and edit report templates and phrases, and imaging settings. */
    public const TEMPLATES_MANAGE = 'imaging.templates.manage';

    /** Write and save report drafts. */
    public const REPORTS_WRITE = 'imaging.reports.write';

    /** Sign a report (it becomes final), and amend a signed one. */
    public const REPORTS_SIGN = 'imaging.reports.sign';

    /** See the imaging list and print signed reports. */
    public const REPORTS_PRINT = 'imaging.reports.print';

    /** When true, the person who signs must not be the one who wrote the draft. */
    public const SETTING_SIGNER_MUST_DIFFER = 'imaging.signer_must_differ';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::TEMPLATES_MANAGE => 'Create and edit imaging report templates and phrases',
            self::REPORTS_WRITE => 'Write imaging report drafts',
            self::REPORTS_SIGN => 'Sign and amend imaging reports',
            self::REPORTS_PRINT => 'See the imaging list and print signed imaging reports',
        ];
    }
}
