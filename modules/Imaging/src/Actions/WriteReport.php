<?php

declare(strict_types=1);

namespace Modules\Imaging\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Settings\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Imaging\Enums;
use Modules\Imaging\Models\ReportVersion;
use Modules\Imaging\Models\Study;
use Modules\Imaging\Models\Template;
use Modules\Imaging\Permissions;
use Modules\Imaging\Support\Html;
use Modules\Imaging\Support\ImagingException;

/**
 * Writing, signing and amending an imaging report. The study state machine:
 *
 *   pending → draft → final → amending → final ...
 *   pending → cancelled (only by the order listeners)
 *
 * Signing writes a new, never-changed report version. Every step is audited
 * in the same transaction. $lockVersion stops two people saving over each
 * other's work.
 */
final class WriteReport
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
    ) {}

    /** @param array{findings?: ?string, impression?: ?string, advice?: ?string, template_id?: ?int} $text */
    public function saveDraft(Study $study, array $text, int $lockVersion): Study
    {
        Gate::authorize(Permissions::REPORTS_WRITE, $study->branch_id);

        return DB::connection('clinic')->transaction(function () use ($study, $text, $lockVersion): Study {
            $study = $this->locked($study, $lockVersion);

            if (! $study->isEditable()) {
                throw new ImagingException('This report is '.strtolower(Enums::status($study->status)).'. Amend it to change it.');
            }

            $clean = $this->clean($text, $study);
            $before = $this->draftOf($study);
            $to = $study->status === 'amending' ? 'amending' : 'draft';

            $this->move($study, $to, [
                'draft_findings' => $clean['findings'],
                'draft_impression' => $clean['impression'],
                'draft_advice' => $clean['advice'],
                'draft_template_id' => $clean['template_id'],
                'draft_by' => Auth::id(),
            ]);

            if ($before !== $this->draftOf($study)) {
                $this->audit->record('imaging.draft_saved', $study, ['status' => $before['status']], [
                    'status' => $to, 'study' => $study->study_name, 'template_id' => $clean['template_id'],
                ]);
            }

            return $study;
        });
    }

    /** Save the text given and sign it: a new final version. */
    public function sign(Study $study, array $text, int $lockVersion): Study
    {
        Gate::authorize(Permissions::REPORTS_SIGN, $study->branch_id);

        return DB::connection('clinic')->transaction(function () use ($study, $text, $lockVersion): Study {
            $study = $this->locked($study, $lockVersion);

            if (! $study->isEditable()) {
                throw new ImagingException('This report is already '.strtolower(Enums::status($study->status)).'.');
            }

            $clean = $this->clean($text, $study);

            if (Html::isBlank($clean['findings']) || Html::isBlank($clean['impression'])) {
                throw new ImagingException('Write the findings and the impression before signing.');
            }

            // The writer of an unchanged saved draft counts; changes made now are the signer's own.
            $writer = $study->draft_by !== null && $this->sameText($study, $clean) ? $study->draft_by : Auth::id();

            if ($this->settings->get(Permissions::SETTING_SIGNER_MUST_DIFFER, false) && $writer === Auth::id()) {
                throw new ImagingException('This clinic needs a second person to sign: save the draft and ask another doctor to sign it.');
            }

            $version = $study->version + 1;

            ReportVersion::create([
                'study_id' => $study->id,
                'version' => $version,
                'findings' => $clean['findings'],
                'impression' => $clean['impression'],
                'advice' => $clean['advice'],
                'template_id' => $clean['template_id'],
                'written_by' => $writer,
                'signed_by' => Auth::id(),
                'signed_at' => now(),
                'amend_reason' => $version > 1 ? $study->amend_reason : null,
            ]);

            $from = $study->status;
            $this->move($study, 'final', [
                'version' => $version,
                'signed_by' => Auth::id(),
                'signed_at' => now(),
                'draft_findings' => null,
                'draft_impression' => null,
                'draft_advice' => null,
                'draft_template_id' => null,
                'draft_by' => null,
                'amend_reason' => null,
            ]);

            $this->audit->record($version > 1 ? 'imaging.amended' : 'imaging.signed', $study, ['status' => $from], [
                'status' => 'final', 'version' => $version, 'study' => $study->study_name,
            ]);

            return $study;
        });
    }

    /** final → amending. The last signed text becomes the draft to correct. */
    public function amend(Study $study, string $reason, int $lockVersion): Study
    {
        Gate::authorize(Permissions::REPORTS_SIGN, $study->branch_id);

        if (blank($reason)) {
            throw new ImagingException('Say what is being corrected.');
        }

        return DB::connection('clinic')->transaction(function () use ($study, $reason, $lockVersion): Study {
            $study = $this->locked($study, $lockVersion);

            if ($study->status !== 'final') {
                throw new ImagingException('Only a signed report can be amended.');
            }

            $last = $study->latestVersion();

            $this->move($study, 'amending', [
                'draft_findings' => $last?->findings,
                'draft_impression' => $last?->impression,
                'draft_advice' => $last?->advice,
                'draft_template_id' => $last?->template_id,
                'draft_by' => null,
                'amend_reason' => mb_substr(trim($reason), 0, 255),
            ]);

            $this->audit->record('imaging.amendment_started', $study, ['status' => 'final'], ['status' => 'amending', 'version' => $study->version], ['reason' => trim($reason)]);

            return $study;
        });
    }

    private function locked(Study $study, int $lockVersion): Study
    {
        $fresh = Study::whereKey($study->id)->lockForUpdate()->firstOrFail();

        if ($fresh->lock_version !== $lockVersion) {
            throw new ImagingException('Someone else changed this report a moment ago. Open it again to see their changes.');
        }

        return $fresh;
    }

    /** @param array<string, mixed> $extra */
    private function move(Study $study, string $to, array $extra): void
    {
        if (! in_array($to, Enums::TRANSITIONS[$study->status] ?? [], true)) {
            throw new ImagingException("A report cannot go from {$study->status} to {$to}.");
        }

        $study->forceFill(['status' => $to, 'lock_version' => $study->lock_version + 1] + $extra)->save();
    }

    /** @return array{findings: string, impression: string, advice: ?string, template_id: ?int} */
    private function clean(array $text, Study $study): array
    {
        $templateId = isset($text['template_id']) && $text['template_id'] !== '' ? (int) $text['template_id'] : null;

        if ($templateId !== null && ! Template::whereKey($templateId)->where('service_id', $study->service_id)->exists()) {
            $templateId = null;
        }

        $advice = trim((string) ($text['advice'] ?? ''));

        return [
            'findings' => Html::clean($text['findings'] ?? ''),
            'impression' => Html::clean($text['impression'] ?? ''),
            'advice' => $advice === '' ? null : mb_substr($advice, 0, 2000),
            'template_id' => $templateId,
        ];
    }

    /** @param array{findings: string, impression: string, advice: ?string} $clean */
    private function sameText(Study $study, array $clean): bool
    {
        return (string) $study->draft_findings === $clean['findings']
            && (string) $study->draft_impression === $clean['impression']
            && $study->draft_advice === $clean['advice'];
    }

    /** @return array<string, mixed> */
    private function draftOf(Study $study): array
    {
        return [
            'status' => $study->status,
            'findings' => $study->draft_findings,
            'impression' => $study->draft_impression,
            'advice' => $study->draft_advice,
            'template_id' => $study->draft_template_id,
        ];
    }
}
