<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use App\Core\Documents\ClinicFiles;
use App\Core\Documents\Letterhead;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
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
            'order' => $order->loadMissing(['patient', 'referralDoctor']),
            'patient' => $order->patient,
            'departments' => $items->groupBy(fn (WorkItem $w): string => $w->department ?: 'Laboratory'),
            'items' => $items,
            'waiting' => $onlyWorkItemIds === null ? $waiting : [],
            'signers' => $signers,
            'amended' => $items->contains(fn (WorkItem $w): bool => $w->version > 1),
            'layout' => [
                'preprinted' => $preprinted,
                'top_mm' => $preprinted ? (int) $this->settings->get(Permissions::SETTING_TOP_MARGIN, 40) : 12,
                'bottom_mm' => $preprinted ? (int) $this->settings->get(Permissions::SETTING_BOTTOM_MARGIN, 25) : 14,
                'footer' => $this->settings->get(Permissions::SETTING_FOOTER),
            ],
            'printed_at' => now($this->clinics->require()->timezone),
        ];
    }
}
