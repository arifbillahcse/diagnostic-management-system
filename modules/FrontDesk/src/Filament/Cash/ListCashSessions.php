<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Cash;

use App\Core\Branches\CurrentBranch;
use App\Core\Money\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Actions\ManageCashSession;
use Modules\FrontDesk\Filament\Concerns\RunsFrontDeskActions;
use Modules\FrontDesk\Models\CashSession;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\CashDrawer;

class ListCashSessions extends ListRecords
{
    use RunsFrontDeskActions;

    protected static string $resource = CashSessionResource::class;

    private function mine(): ?CashSession
    {
        return app(CashDrawer::class)->openSessionOf(Auth::user());
    }

    private function amount(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->prefix('৳')->required()
            ->rule('regex:/^\d{1,10}(\.\d{1,2})?$/')
            ->validationMessages(['regex' => 'Enter an amount such as 500 or 499.50.']);
    }

    public function getSubheading(): ?string
    {
        $session = $this->mine();

        return $session === null
            ? 'You have no open cash session. Open one before taking cash.'
            : 'Your session is open. The drawer should hold ৳ '.app(CashDrawer::class)->expected($session)->formatted().' now.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Open my session')
                ->visible(fn (): bool => Gate::allows(Permissions::CASH_MANAGE) && $this->mine() === null)
                ->fillForm(['opening_float' => '0'])
                ->schema([$this->amount('opening_float', 'Cash in the drawer now')])
                ->action(fn (array $data) => $this->attempt(
                    fn () => app(ManageCashSession::class)->open(app(CurrentBranch::class)->get(), Money::fromDecimal($data['opening_float'])),
                    'Cash session opened',
                )),

            Action::make('close')
                ->label('Close my session')
                ->color('gray')
                ->visible(fn (): bool => Gate::allows(Permissions::CASH_MANAGE) && $this->mine() !== null)
                ->modalDescription('Count the cash in the drawer and enter the total. A closed session cannot be reopened.')
                ->schema([
                    $this->amount('counted_cash', 'Cash counted'),
                    Textarea::make('notes')->rows(2)->maxLength(500)->helperText('Required when the count differs from the expected amount.'),
                ])
                ->action(fn (array $data) => $this->attempt(
                    fn () => app(ManageCashSession::class)->close($this->mine(), Money::fromDecimal($data['counted_cash']), $data['notes'] ?? null),
                    'Cash session closed',
                )),
        ];
    }
}
