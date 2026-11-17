<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament;

/**
 * The screens this module adds to the panel.
 */
final class Screens
{
    /** @return list<class-string> */
    public static function resources(): array
    {
        return [
            Samples\SampleResource::class,
            Samples\TransferResource::class,
            Work\WorkItemResource::class,
            Catalog\LabTestResource::class,
            Catalog\AnalyteResource::class,
            Catalog\SampleTypeResource::class,
        ];
    }

    /** @return list<class-string> */
    public static function pages(): array
    {
        return [LabSettings::class, CollectionCentres::class];
    }
}
