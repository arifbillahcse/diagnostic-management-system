<?php

declare(strict_types=1);

namespace Modules\Imaging\Support;

use App\Core\Documents\Letterhead;
use App\Core\Documents\PageLayout;
use App\Core\Documents\Signers;
use App\Core\Tenancy\ClinicContext;
use Modules\FrontDesk\Models\Order;
use Modules\Imaging\Models\Study;

/**
 * Everything a printed imaging report shows. Only signed reports are
 * included, each at its latest version; one study per page.
 */
final class ReportData
{
    public function __construct(
        private readonly Letterhead $letterhead,
        private readonly PageLayout $layout,
        private readonly Signers $signers,
        private readonly ClinicContext $clinics,
    ) {}

    /**
     * @param  list<int>|null  $onlyStudyIds
     * @return array<string, mixed>
     */
    public function forOrder(Order $order, ?array $onlyStudyIds = null): array
    {
        $studies = Study::where('order_id', $order->id)->where('status', 'final')
            ->when($onlyStudyIds !== null, fn ($q) => $q->whereIn('id', $onlyStudyIds))
            ->orderBy('id')->get();

        if ($studies->isEmpty()) {
            throw new ImagingException('No signed imaging report is ready to print for this order.');
        }

        $order->loadMissing(['patient', 'referralDoctor', 'invoice']);
        $doctor = $order->referralDoctor;

        $pages = $studies->map(function (Study $study): array {
            $version = $study->latestVersion();

            return [
                'study' => $study,
                'version' => $version,
                'signer' => $this->signers->for([$version->signed_by])[0] ?? null,
            ];
        })->all();

        return [
            'clinic' => $this->letterhead->data(),
            'layout' => $this->layout->data(),
            'timezone' => $this->clinics->require()->timezone,
            'order' => $order,
            'patient' => $order->patient,
            'bill_no' => $order->invoice?->invoice_no,
            'consultant' => $doctor ? trim(($doctor->code ? $doctor->code.', ' : '').$doctor->name.($doctor->degree ? ', '.$doctor->degree : '')) : null,
            'pages' => $pages,
            'printed_at' => now($this->clinics->require()->timezone),
        ];
    }
}
