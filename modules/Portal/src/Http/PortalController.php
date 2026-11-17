<?php

declare(strict_types=1);

namespace Modules\Portal\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use App\Core\Documents\Letterhead;
use App\Core\Numbering\NumberGenerator;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\FrontDesk\Models\Order;
use Modules\Portal\Support\PortalAccess;
use Modules\Portal\Support\PortalReports;

/**
 * The patient's report page on the clinic's own website. Nobody signs in:
 * the bill number and mobile number together are the key, and the answer to
 * a wrong pair never says which part was wrong.
 */
final class PortalController
{
    public function __construct(
        private readonly PortalAccess $access,
        private readonly PortalReports $reports,
        private readonly Letterhead $letterhead,
        private readonly AuditLogger $audit,
    ) {}

    public function form(): Response
    {
        return $this->page('portal::form', []);
    }

    /** A bill number in this clinic's own format, as the example in the form. */
    private function example(): string
    {
        try {
            $format = app(NumberGenerator::class)->format('invoice');

            return $format->render(34, now(app(ClinicContext::class)->timezone()), (string) Branch::orderBy('id')->value('code'));
        } catch (\Throwable) {
            return '2611-00034';
        }
    }

    public function lookup(Request $request): Response|RedirectResponse
    {
        $data = $request->validate([
            'bill_no' => ['required', 'string', 'max:40'],
            'mobile' => ['required', 'string', 'max:20'],
        ], [], ['bill_no' => 'bill number', 'mobile' => 'mobile number']);

        if ($this->access->tooManyTries($data['bill_no'])) {
            return $this->page('portal::form', ['error' => 'Too many tries for this bill number. Please wait 15 minutes, or contact the clinic.'], 429);
        }

        $order = $this->access->find($data['bill_no'], $data['mobile']);

        if ($order === null) {
            $this->access->failed($data['bill_no']);

            return $this->page('portal::form', ['error' => 'We could not find a bill with that bill number and mobile number. Please check both and try again.', 'old' => ['bill_no' => $data['bill_no']]], 422);
        }

        $this->access->grant($order);
        $this->audit->record('portal.opened', $order, [], [], ['order_no' => $order->order_no]);

        return redirect()->route('portal.show', $order->public_id);
    }

    public function show(Order $order): Response|RedirectResponse
    {
        if (! $this->access->allows($order)) {
            return redirect()->route('portal.form');
        }

        return $this->page('portal::order', [
            'order' => $order->loadMissing('patient'),
            'summary' => $this->reports->summary($order),
            'held' => $this->reports->heldForDue($order),
        ]);
    }

    public function download(Order $order, string $kind): Response|RedirectResponse
    {
        if (! $this->access->allows($order)) {
            return redirect()->route('portal.form');
        }

        if ($this->reports->heldForDue($order)) {
            return redirect()->route('portal.show', $order->public_id);
        }

        $bytes = $this->reports->pdf($order, $kind);

        if ($bytes === null) {
            return redirect()->route('portal.show', $order->public_id);
        }

        $this->audit->record('portal.downloaded', $order, [], [], ['order_no' => $order->order_no, 'report' => $kind]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$kind.'-report-'.$order->order_no.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function leave(): RedirectResponse
    {
        $this->access->forgetAll();

        return redirect()->route('portal.form');
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data, int $status = 200): Response
    {
        return response()->view($view, $data + ['clinic' => $this->letterhead->data(), 'example' => $this->example()], $status)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, no-store');
    }
}
