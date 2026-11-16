<?php

declare(strict_types=1);

namespace Modules\Laboratory;

/**
 * Permission and setting keys of the laboratory module.
 */
final class Permissions
{
    public const CATALOG_MANAGE = 'laboratory.catalog.manage';

    public const WORK_VIEW = 'laboratory.work.view';

    public const SAMPLES_COLLECT = 'laboratory.samples.collect';

    public const SAMPLES_RECEIVE = 'laboratory.samples.receive';

    public const SAMPLES_REJECT = 'laboratory.samples.reject';

    public const RESULTS_ENTER = 'laboratory.results.enter';

    /** Sign off entered results. A clinical responsibility. */
    public const RESULTS_VERIFY = 'laboratory.results.verify';

    /** Reopen a released result to correct it. */
    public const RESULTS_AMEND = 'laboratory.results.amend';

    public const SETTINGS_MANAGE = 'laboratory.settings.manage';

    /** Setting: must results be verified by someone other than the person who entered them? */
    public const SETTING_VERIFIER_MUST_DIFFER = 'laboratory.verifier_must_differ';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::CATALOG_MANAGE => 'Manage lab tests, analytes, reference ranges, and sample types',
            self::WORK_VIEW => 'View samples, the worklist, and results',
            self::SAMPLES_COLLECT => 'Collect samples and print labels',
            self::SAMPLES_RECEIVE => 'Receive samples in the laboratory',
            self::SAMPLES_REJECT => 'Reject a sample',
            self::RESULTS_ENTER => 'Enter results',
            self::RESULTS_VERIFY => 'Verify and release results',
            self::RESULTS_AMEND => 'Amend released results',
            self::SETTINGS_MANAGE => 'Change laboratory settings',
        ];
    }
}
