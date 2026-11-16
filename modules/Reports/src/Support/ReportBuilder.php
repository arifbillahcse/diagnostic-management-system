<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use App\Core\Documents\Barcode;
use App\Core\Documents\ClinicFiles;
use App\Core\Documents\Letterhead;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Support\Collection;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\WorkItem;
use Modules\Reports\Models\Signatory;
use Modules\Reports\Permissions;

/**
 * Gathers everything a lab report shows. Only released results are ever
 * included; a test that is waiting, unverified, or being amended is left out.
 */
final class ReportBuilder
{
    public function __construct(
        private readonly Letterhead $letterhead,
        private readonly Settings $settings,
        private readonly ClinicFiles $files,
        private readonly ClinicContext $clinics,
    ) {}

    /**
     * @param  list<int>|null  $onlyWorkItemIds  limit the report to these tests
     * @return array<string, mixed>
     */
    public function forOrder(Order $order, ?array $onlyWorkItemIds = null): array
    {
        $items = WorkItem::query()
            ->where('order_id', $order->id)
            ->where('status', WorkStatus::Released->value)
            ->when($onlyWorkItemIds !== null, fn ($query) => $query->whereIn('id', $onlyWorkItemIds))
            ->with(['results', 'labTest', 'sample.sampleType', 'verifier'])
            ->orderBy('department')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            throw new ReportException('No verified results are ready to print for this order.');
        }

        $waiting = WorkItem::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', [WorkStatus::Released->value, WorkStatus::Cancelled->value])
            ->pluck('test_name')
            ->all();

        $signatories = Signatory::whereIn('user_id', $items->pluck('verified_by')->unique())->get()->keyBy('user_id');

        $signers = $items->pluck('verifier')->filter()->unique('id')->map(function ($user) use ($signatories): array {
            $signatory = $signatories->get($user->id);

            return [
                'name' => $signatory?->display_name ?? $user->name,
                'lines' => $signatory?->qualificationLines() ?? [],
                'signature' => $this->files->dataUri($signatory?->signature_path),
            ];
        })->values()->all();

        $preprinted = $this->settings->get(Permissions::SETTING_LETTERHEAD, 'print') === 'preprinted';

        return [
            'clinic' => $this->letterhead->data(),
            'timezone' => $this->clinics->require()->timezone,
            'order' => $order->loadMissing(['patient', 'referralDoctor', 'invoice']),
            'info' => $this->info($order, $items),
            'patient' => $order->patient,
            'departments' => $items->groupBy(fn (WorkItem $w): string => $w->department ?: 'Laboratory'),
            'items' => $items,
            'waiting' => $onlyWorkItemIds === null ? $waiting : [],
            'signers' => $signers,
            'amended' => $items->contains(fn (WorkItem $w): bool => $w->version > 1),
            'layout' => $this->layout($preprinted),
            'printed_at' => now($this->clinics->require()->timezone),
        ];
    }

    /**
     * What the top block of the report says besides the patient: bill and
     * lab numbers (with barcodes), specimen, and the dates.
     *
     * @param  Collection<int, WorkItem>  $items
     * @return array<string, mixed>
     */
    private function info(Order $order, $items): array
    {
        $samples = $items->pluck('sample')->filter()->unique('id')->sortBy('collected_at')->values();
        $labNos = $samples->pluck('accession_no')->all();
        $billNo = $order->invoice?->invoice_no;
        $doctor = $order->referralDoctor;

        return [
            'bill_no' => $billNo,
            'lab_no' => implode(', ', $labNos),
            'bill_barcode' => $billNo ? Barcode::pngDataUri($billNo) : null,
            'lab_barcode' => $labNos !== [] ? Barcode::pngDataUri($labNos[0]) : null,
            'specimen' => $samples->map(fn ($s) => $s->sampleType?->name)->filter()->unique()->implode(', '),
            'received_at' => $samples->pluck('received_at')->filter()->sort()->first(),
            'collected_at' => $samples->pluck('collected_at')->filter()->sort()->first(),
            'delivery_at' => $order->delivery_at,
            'consultant' => $doctor ? trim(($doctor->code ? $doctor->code.', ' : '').$doctor->name.($doctor->degree ? ', '.$doctor->degree : '')) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function layout(bool $preprinted): array
    {
        $header = $preprinted ? null : $this->banner(Permissions::SETTING_HEADER_IMAGE, 70);
        $footer = $preprinted ? null : $this->banner(Permissions::SETTING_FOOTER_IMAGE, 40);
        $logo = $this->settings->get(Permissions::SETTING_WATERMARK, false) && ! $preprinted
            ? $this->files->dataUri($this->settings->get('clinic.logo_path'))
            : null;

        return [
            'preprinted' => $preprinted,
            'header_image' => $header,
            'footer_image' => $footer,
            'watermark' => $logo,
            // Margins leave room for the banners, which are printed edge to edge.
            'top_mm' => $preprinted ? (int) $this->settings->get(Permissions::SETTING_TOP_MARGIN, 40) : ($header ? (int) ceil($header['height_mm']) + 4 : 12),
            'bottom_mm' => $preprinted ? (int) $this->settings->get(Permissions::SETTING_BOTTOM_MARGIN, 25) : ($footer ? (int) ceil($footer['height_mm']) + 8 : 14),
            'footer' => $this->settings->get(Permissions::SETTING_FOOTER),
        ];
    }

    /**
     * A banner stretched across an A4 page (210 mm). One taller than the
     * limit is shown at the limit's height instead, keeping its proportions.
     *
     * @return array{uri: string, width_mm: float, height_mm: float}|null
     */
    private function banner(string $setting, int $maxHeightMm): ?array
    {
        $uri = $this->files->dataUri($this->settings->get($setting));
        $size = $uri === null ? false : @getimagesizefromstring((string) base64_decode(substr($uri, strpos($uri, ',') + 1), true));

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        $height = 210 * $size[1] / $size[0];

        if ($height > $maxHeightMm) {
            return ['uri' => $uri, 'width_mm' => round($maxHeightMm * $size[0] / $size[1], 1), 'height_mm' => (float) $maxHeightMm];
        }

        return ['uri' => $uri, 'width_mm' => 210.0, 'height_mm' => round($height, 1)];
    }
}
