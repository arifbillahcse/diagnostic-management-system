<?php

declare(strict_types=1);

namespace Modules\Reports;

use App\Core\Documents\PageLayout;

/**
 * Permission and setting keys of the reports module. The page layout keys
 * now belong to the core (PageLayout) and are kept here as aliases.
 */
final class Permissions
{
    public const REPORTS_VIEW = 'reports.reports.view';

    public const REPORTS_PRINT = 'reports.reports.print';

    /** Print a report although the invoice has a due, when the clinic blocks that. */
    public const REPORTS_PRINT_WITH_DUE = 'reports.reports.print_with_due';

    public const SETTINGS_MANAGE = 'reports.settings.manage';

    /** "print" draws the clinic header; "preprinted" leaves space for pre-printed paper. */
    public const SETTING_LETTERHEAD = PageLayout::SETTING_LETTERHEAD;

    public const SETTING_TOP_MARGIN = PageLayout::SETTING_TOP_MARGIN;

    public const SETTING_BOTTOM_MARGIN = PageLayout::SETTING_BOTTOM_MARGIN;

    public const SETTING_FOOTER = PageLayout::SETTING_FOOTER;

    /** Full-width banner images for the top and bottom of every page (plain paper only). */
    public const SETTING_HEADER_IMAGE = PageLayout::SETTING_HEADER_IMAGE;

    public const SETTING_FOOTER_IMAGE = PageLayout::SETTING_FOOTER_IMAGE;

    /** Faint copy of the logo behind the results. */
    public const SETTING_WATERMARK = PageLayout::SETTING_WATERMARK;

    /** Banner file size limits, in KB. They keep PDF creation fast. */
    public const HEADER_IMAGE_MAX_KB = PageLayout::HEADER_IMAGE_MAX_KB;

    public const FOOTER_IMAGE_MAX_KB = PageLayout::FOOTER_IMAGE_MAX_KB;

    public const SETTING_BLOCK_WHEN_DUE = 'reports.block_print_when_due';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::REPORTS_VIEW => 'View the list of reports ready to print',
            self::REPORTS_PRINT => 'Print lab reports',
            self::REPORTS_PRINT_WITH_DUE => 'Print a report while the invoice has a due',
            self::SETTINGS_MANAGE => 'Change lab report printing settings',
        ];
    }
}
