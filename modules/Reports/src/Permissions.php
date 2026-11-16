<?php

declare(strict_types=1);

namespace Modules\Reports;

/**
 * Permission and setting keys of the reports module.
 */
final class Permissions
{
    public const REPORTS_VIEW = 'reports.reports.view';

    public const REPORTS_PRINT = 'reports.reports.print';

    /** Print a report although the invoice has a due, when the clinic blocks that. */
    public const REPORTS_PRINT_WITH_DUE = 'reports.reports.print_with_due';

    public const SETTINGS_MANAGE = 'reports.settings.manage';

    /** "print" draws the clinic header; "preprinted" leaves space for pre-printed paper. */
    public const SETTING_LETTERHEAD = 'reports.letterhead';

    public const SETTING_TOP_MARGIN = 'reports.top_margin_mm';

    public const SETTING_BOTTOM_MARGIN = 'reports.bottom_margin_mm';

    public const SETTING_FOOTER = 'reports.footer_text';

    /** Full-width banner images for the top and bottom of every page (plain paper only). */
    public const SETTING_HEADER_IMAGE = 'reports.header_image_path';

    public const SETTING_FOOTER_IMAGE = 'reports.footer_image_path';

    /** Faint copy of the logo behind the results. */
    public const SETTING_WATERMARK = 'reports.watermark';

    /** Banner file size limits, in KB. They keep PDF creation fast. */
    public const HEADER_IMAGE_MAX_KB = 150;

    public const FOOTER_IMAGE_MAX_KB = 60;

    public const SETTING_BLOCK_WHEN_DUE = 'reports.block_print_when_due';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::REPORTS_VIEW => 'View the list of reports ready to print',
            self::REPORTS_PRINT => 'Print lab reports',
            self::REPORTS_PRINT_WITH_DUE => 'Print a report while the invoice has a due',
            self::SETTINGS_MANAGE => 'Change report layout and signatories',
        ];
    }
}
