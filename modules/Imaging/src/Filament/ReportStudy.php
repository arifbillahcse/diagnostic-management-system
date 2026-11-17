<?php

declare(strict_types=1);

namespace Modules\Imaging\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Modules\Imaging\Actions\WriteReport;
use Modules\Imaging\Enums;
use Modules\Imaging\Models\Phrase;
use Modules\Imaging\Models\Study;
use Modules\Imaging\Models\Template;
use Modules\Imaging\Permissions;

/**
 * Writing one imaging report: choose a template, edit the findings and
 * impression, save a draft, sign, and later amend. A signed report shows
 * its text and every earlier version.
 *
 * @property Study $record
 */
class ReportStudy extends Page
{
    use InteractsWithRecord;

    protected static string $resource = StudyResource::class;

    protected string $view = 'imaging::report';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public int $lockVersion = 0;

    public ?int $templateId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(static::getResource()::canView($this->getRecord()), 403);
        $this->fillFromRecord();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->study_name.' · '.$this->record->patient?->name;
    }

    private function fillFromRecord(): void
    {
        $this->record->refresh();
        $this->lockVersion = $this->record->lock_version;
        $this->templateId = $this->record->draft_template_id;
        $this->form->fill([
            'findings' => $this->record->draft_findings,
            'impression' => $this->record->draft_impression,
            'advice' => $this->record->draft_advice,
        ]);
    }

    public function canWrite(): bool
    {
        return $this->record->isEditable()
            && (Gate::allows(Permissions::REPORTS_WRITE, $this->record->branch_id) || Gate::allows(Permissions::REPORTS_SIGN, $this->record->branch_id));
    }

    /** @return array<int, string> templates of this test that suit the patient */
    public function templateOptions(): array
    {
        $sex = $this->record->patient?->sex?->value;

        return Template::where('service_id', $this->record->service_id)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Template $t): bool => $t->suits($sex))
            ->pluck('name', 'id')->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TemplateResource::editor('findings', 'Findings'),
            TemplateResource::editor('impression', 'Impression'),
            Textarea::make('advice')->rows(2)->maxLength(2000),
        ])->disabled(fn (): bool => ! $this->canWrite());
    }

    /** @return array<string, mixed> */
    private function text(): array
    {
        return $this->form->getState() + ['template_id' => $this->templateId];
    }

    protected function getHeaderActions(): array
    {
        $can = fn (string $p): bool => Gate::allows($p, $this->record->branch_id);

        return [
            Action::make('template')->label('Use a template')->color('gray')
                ->visible(fn (): bool => $this->canWrite())
                ->modalDescription('The findings, impression and advice are replaced by the template\'s text.')
                ->schema([
                    Select::make('template_id')->label('Template')->required()->native(false)
                        ->options(fn (): array => $this->templateOptions())
                        ->helperText(fn (): ?string => $this->templateOptions() === [] ? 'No template for this test yet. Add one under Imaging, Report templates.' : null),
                ])
                ->action(function (array $data): void {
                    $template = Template::where('service_id', $this->record->service_id)->findOrFail($data['template_id']);
                    $this->form->fill([
                        'findings' => $template->findings,
                        'impression' => $template->impression,
                        'advice' => $template->advice,
                    ]);
                    $this->templateId = $template->id;
                }),

            Action::make('phrase')->label('Add a phrase')->color('gray')
                ->visible(fn (): bool => $this->canWrite())
                ->schema([
                    Select::make('phrase_id')->label('Phrase')->required()->searchable()
                        ->options(fn (): array => Phrase::where('is_active', true)->orderBy('title')->pluck('title', 'id')->all()),
                ])
                ->action(function (array $data): void {
                    $phrase = Phrase::findOrFail($data['phrase_id']);
                    $state = $this->form->getState();
                    $this->form->fill([
                        'findings' => ($state['findings'] ?? '').'<p>'.e($phrase->body).'</p>',
                        'impression' => $state['impression'] ?? null,
                        'advice' => $state['advice'] ?? null,
                    ]);
                }),

            Action::make('save')->label('Save draft')->color('gray')
                ->visible(fn (): bool => $this->canWrite() && $can(Permissions::REPORTS_WRITE))
                ->action(function (): void {
                    if (Guard::run(null, fn () => app(WriteReport::class)->saveDraft($this->record, $this->text(), $this->lockVersion), 'Draft saved') !== null) {
                        $this->fillFromRecord();
                    }
                }),

            Action::make('sign')->label('Sign report')
                ->visible(fn (): bool => $this->canWrite() && $can(Permissions::REPORTS_SIGN))
                ->requiresConfirmation()
                ->modalDescription('The report becomes final and can be printed. A later correction makes a new version; this one is kept.')
                ->action(function (): void {
                    if (Guard::run(null, fn () => app(WriteReport::class)->sign($this->record, $this->text(), $this->lockVersion), 'Report signed') !== null) {
                        $this->fillFromRecord();
                    }
                }),

            Action::make('print')->label('Print')
                ->visible(fn (): bool => $this->record->status === 'final' && $can(Permissions::REPORTS_PRINT))
                ->url(fn (): string => route('imaging.print', ['order' => $this->record->order->public_id, 'study' => $this->record->public_id]), shouldOpenInNewTab: true),

            Action::make('amend')->label('Amend')->color('warning')
                ->visible(fn (): bool => $this->record->status === 'final' && $can(Permissions::REPORTS_SIGN))
                ->modalDescription('The signed text opens for correction. When it is signed again it becomes a new version; the old version is kept.')
                ->schema([TextInput::make('reason')->label('What is being corrected')->required()->maxLength(255)])
                ->action(function (Action $action, array $data): void {
                    if (Guard::run($action, fn () => app(WriteReport::class)->amend($this->record, (string) $data['reason'], $this->lockVersion), 'Report opened for amendment') !== null) {
                        $this->fillFromRecord();
                    }
                }),
        ];
    }

    public function statusLabel(): string
    {
        return Enums::status($this->record->status);
    }
}
