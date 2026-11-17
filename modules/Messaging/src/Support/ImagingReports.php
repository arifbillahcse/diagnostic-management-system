<?php

declare(strict_types=1);

namespace Modules\Messaging\Support;

use App\Core\Modules\ModuleState;
use Modules\FrontDesk\Models\Order;
use Modules\Imaging\Actions\PrintImagingReport;
use Modules\Imaging\Models\Study;

/**
 * What messaging uses from the imaging module when it is installed and on.
 * Imaging is only suggested, never required, so every use checks first.
 */
final class ImagingReports
{
    public static function on(): bool
    {
        return class_exists(Study::class) && app(ModuleState::class)->isEnabled('imaging');
    }

    /** An imaging report of the order is not signed yet. */
    public static function waiting(int $orderId): bool
    {
        return Study::where('order_id', $orderId)->whereNotIn('status', ['final', 'cancelled'])->exists();
    }

    public static function hasSigned(int $orderId): bool
    {
        return self::on() && Study::where('order_id', $orderId)->where('status', 'final')->exists();
    }

    /** Lab results (when released) and signed imaging reports in one PDF. */
    public static function pdfFor(Order $order): string
    {
        return app(PrintImagingReport::class)->forPatient($order);
    }
}
