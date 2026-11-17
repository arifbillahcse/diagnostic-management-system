<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament;

use App\Core\Auth\CorePermissions;
use App\Core\Branches\CurrentBranch;
use App\Core\Modules\GatesModuleAccess;
use App\Core\Money\Money;
use App\Core\Patients\Actions\SavePatient;
use App\Core\Patients\Patient;
use App\Core\Patients\PhoneNumber;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Actions\RecordPayment;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\Addons;
use Modules\FrontDesk\Support\CashDrawer;
use Modules\FrontDesk\Support\DeliveryTime;
use Modules\FrontDesk\Support\DiscountRules;
use Modules\FrontDesk\Support\FrontDeskException;
use Modules\FrontDesk\Support\PrintLinks;
use UnitEnum;

/**
 * The counter screen: one page for the patient, the tests, the discount, and
 * the payment, kept small so it fits a 1366 x 768 monitor without scrolling.
 *
 * It holds no billing rules of its own. A new patient is registered with
 * SavePatient, the bill is made by PlaceOrder, and the money is taken by
 * RecordPayment, the same actions every other screen uses.
 */
class NewBill extends Page
{
    use GatesModuleAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Front desk';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'New bill';

    protected static ?string $title = 'New bill';

    protected static ?string $slug = 'new-bill';

    protected string $view = 'frontdesk::new-bill';

    // Patient
    /** The code of the patient chosen (shown in the banner). */
    #[Locked]
    public string $patientCode = '';

    /** What is typed in the "find patient" box, and what it found. */
    public string $patientSearch = '';

    /** @var list<array{id: int, code: string, name: string, age: string, sex: string, phone: string}> */
    #[Locked]
    public array $searchResults = [];

    public string $name = '';

    public string $age = '';

    public string $ageUnit = 'Y';

    public string $sex = 'male';

    public string $mobile = '';

    public string $address = '';

    /** Set when the patient already exists (chosen, or registered during this bill). Locked: the browser must not be able to change it. */
    #[Locked]
    public ?int $patientId = null;

    /** The people already registered with the mobile number typed. @var list<array{id: int, code: string, name: string, age: string, sex: string, phone: string}> */
    #[Locked]
    public array $suggestions = [];

    // The bill
    public ?int $doctorId = null;

    /** @var list<int> */
    public array $serviceIds = [];

    // Two linked boxes. Typing in one fills the other; the one typed in last
    // is the one the bill is made from ("percent" or "amount").
    public string $discountPercent = '';

    public string $discountAmount = '';

    public string $discountSource = '';

    public string $discountReason = '';

    public string $deliveryAt = '';

    public string $notes = '';

    // The money
    public string $payMethod = 'cash';

    public string $payReference = '';

    public string $paid = '';

    public bool $paidTouched = false;

    /** One per form: a double press of Save cannot make two bills. */
    public string $billToken = '';

    public string $paymentKey = '';

    /** The bill saved just before, shown as a strip with its print links. @var array<string, mixed>|null */
    #[Locked]
    public ?array $last = null;

    /** The print options after a save: shown while true. */
    public bool $printPanel = false;

    /** @var array<string, Money>|null */
    private ?array $figures = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $lineCache = null;

    public static function moduleKey(): string
    {
        return 'frontdesk';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::ORDERS_CREATE;
    }

    public static function canAccess(): bool
    {
        return static::moduleAllows();
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function mount(): void
    {
        $this->newBill();
    }

    /** A blank bill, with the cursor in the first box. The strip showing the last saved bill stays. */
    public function newBill(): void
    {
        $this->blank();
        $this->dispatch('bill-focus', target: 'search');
    }

    private function blank(): void
    {
        $this->reset([
            'patientCode', 'patientSearch', 'searchResults', 'name', 'age', 'ageUnit', 'sex', 'mobile', 'address', 'patientId', 'suggestions', 'doctorId',
            'serviceIds', 'discountPercent', 'discountAmount', 'discountSource', 'discountReason', 'notes', 'payMethod', 'payReference', 'paid', 'paidTouched',
            'printPanel',
        ]);

        $this->billToken = (string) Str::uuid();
        $this->paymentKey = (string) Str::uuid();
        $this->deliveryAt = app(DeliveryTime::class)->after(now())->timezone(app(ClinicContext::class)->require()->timezone)->format('Y-m-d\TH:i');
        $this->forgetCaches();
    }

    // Patient

    /** Looking up an existing patient shows personal details, so it needs the permission to view patients. */
    private function mayLookUp(): bool
    {
        return Gate::allows(CorePermissions::PATIENTS_VIEW);
    }

    /** The "find patient" box: as you type, the patients whose ID, name, or mobile starts with it. */
    public function updatedPatientSearch(): void
    {
        $this->searchResults = $this->lookUp($this->patientSearch);
    }

    /** @return list<array{id: int, code: string, name: string, age: string, sex: string, phone: string}> */
    private function lookUp(string $term, int $limit = 8): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2 || ! $this->mayLookUp()) {
            return [];
        }

        return $this->suggest(Patient::search($term)->orderBy('patient_code')->limit($limit)->get());
    }

    /**
     * Enter in the find box with nothing to pick: an exact patient ID opens
     * that patient; anything else starts a new patient with what was typed.
     */
    public function findPatient(): void
    {
        $term = trim($this->patientSearch);

        if ($term === '') {
            return;
        }

        if ($this->mayLookUp()) {
            $matches = Patient::search($term)->orderBy('patient_code')->limit(2)->get();
            $exact = $matches->first(fn (Patient $p): bool => strcasecmp($p->patient_code, $term) === 0);

            if ($exact !== null || $matches->count() === 1) {
                $this->usePatient(($exact ?? $matches->first())->id);

                return;
            }

            if ($matches->count() > 1) {
                $this->searchResults = $this->lookUp($term);

                return;
            }
        }

        $this->startNew();
    }

    /** Nobody matched: what was typed becomes the new patient's mobile number or name. */
    public function startNew(): void
    {
        $term = trim($this->patientSearch);
        $isNumber = $term !== '' && preg_match('/^[\d\s+()\-]+$/', $term) === 1;

        if ($term !== '' && $this->patientId === null) {
            $isNumber ? $this->mobile = $term : $this->name = $term;
        }

        $this->patientSearch = '';
        $this->searchResults = [];
        $this->updatedMobile();
        $this->dispatch('bill-focus', target: $isNumber ? 'name' : 'mobile');
    }

    /** Hide the list and forget what was typed. */
    public function closeSearch(): void
    {
        $this->patientSearch = '';
        $this->searchResults = [];
    }

    /** The mobile number was typed: show the people already registered with it. */
    public function updatedMobile(): void
    {
        $this->forgetCaches();

        if ($this->patientId !== null || ! $this->mayLookUp()) {
            return;
        }

        $normalized = PhoneNumber::normalize($this->mobile);
        $this->suggestions = $normalized === null || strlen($normalized) < 10
            ? []
            : $this->suggest(Patient::where('phone_normalized', $normalized)->orderBy('patient_code')->limit(6)->get());
    }

    public function usePatient(int $id): void
    {
        $patient = $this->mayLookUp() ? Patient::find($id) : null;

        if ($patient === null) {
            return;
        }

        $this->patientId = $patient->id;
        $this->patientCode = $patient->patient_code;
        $this->name = $patient->name;
        $this->sex = $patient->sex->value;
        $this->mobile = (string) $patient->phone;
        $this->address = (string) $patient->address;
        $this->age = $patient->ageLabel();
        $this->suggestions = [];
        $this->patientSearch = '';
        $this->searchResults = [];
        $this->resetErrorBag();
        $this->dispatch('bill-focus', target: 'code');
    }

    /** Forget the chosen patient and type a new one. */
    public function clearPatient(): void
    {
        $this->reset(['patientCode', 'patientSearch', 'searchResults', 'name', 'age', 'ageUnit', 'sex', 'mobile', 'address', 'patientId', 'suggestions']);
        $this->dispatch('bill-focus', target: 'search');
    }

    // Tests

    /** Enter in the code box. */
    public function addByCode(string $code): void
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return;
        }

        $service = Service::where('is_active', true)->where('code', $code)->first();

        if ($service === null) {
            Notification::make()->warning()->title("No test with code {$code}")->send();
        } else {
            $this->add($service);
        }

        $this->dispatch('bill-focus', target: 'code');
    }

    /** The list and the Add button. */
    public function addById(int $id): void
    {
        $service = Service::where('is_active', true)->find($id);

        if ($service !== null) {
            $this->add($service);
        }

        $this->dispatch('bill-focus', target: 'code');
    }

    public function removeService(int $id): void
    {
        $this->serviceIds = array_values(array_filter($this->serviceIds, fn (int $existing): bool => $existing !== $id));
        $this->changed();
    }

    private function add(Service $service): void
    {
        if (in_array($service->id, $this->serviceIds, true)) {
            Notification::make()->warning()->title("{$service->name} is already on this bill")->send();

            return;
        }

        $this->serviceIds[] = $service->id;
        $this->changed();
    }

    // Live figures

    public function updatedDiscountPercent(): void
    {
        $this->discountSource = trim($this->discountPercent) === '' ? '' : 'percent';
        $this->changed();
    }

    public function updatedDiscountAmount(): void
    {
        $this->discountSource = trim($this->discountAmount) === '' ? '' : 'amount';
        $this->changed();
    }

    public function updatedPaid(): void
    {
        $this->paidTouched = true;
        $this->forgetCaches();
    }

    /** The "Exact amount" link: pay what is owed, and follow the net payable again. */
    public function payExact(): void
    {
        $this->paidTouched = false;
        $this->changed();
    }

    /** Until the cashier types an amount, "paid" follows the net payable, as on the paper counter. */
    private function changed(): void
    {
        $this->forgetCaches();
        $this->syncDiscountBoxes();

        if (! $this->paidTouched) {
            $net = $this->figures()['net'];
            $this->paid = $net->isPositive() ? $net->toDecimal() : '';
            $this->forgetCaches(); // the figures above were worked out before "paid" changed
        }
    }

    /** Fill the box that was not typed in, so both always show the same discount. */
    private function syncDiscountBoxes(): void
    {
        $subtotal = $this->figures()['subtotal'];

        if ($this->discountSource === '') {
            $this->discountPercent = $this->discountAmount = '';
        } elseif ($this->discountSource === 'percent') {
            try {
                $amount = app(DiscountRules::class)->amount($subtotal, 'percent', $this->discountPercent);
                $this->discountAmount = $amount->isZero() ? '' : $amount->toDecimal();
            } catch (FrontDeskException) {
                $this->discountAmount = '';
            }
        } else {
            $amount = $this->money($this->discountAmount);
            $this->discountPercent = $amount === null || ! $subtotal->isPositive()
                ? ''
                : rtrim(rtrim(number_format($amount->minor * 100 / $subtotal->minor, 2, '.', ''), '0'), '.');
        }

        $this->forgetCaches();
    }

    private function forgetCaches(): void
    {
        $this->figures = null;
        $this->lineCache = null;
    }

    /**
     * What is on the bill: the chosen services in order, then the supplies
     * added automatically.
     *
     * @return list<array{id: int, code: string, name: string, department: string, rate: Money, auto: bool}>
     */
    public function lines(): array
    {
        if ($this->lineCache !== null) {
            return $this->lineCache;
        }

        $found = Service::with(['category', 'packageItems'])->whereIn('id', $this->serviceIds)->get()->keyBy('id');
        $chosen = array_values(array_filter(array_map(fn (int $id): ?Service => $found[$id] ?? null, $this->serviceIds)));
        $rows = [];

        foreach ([[$chosen, false], [app(Addons::class)->for($chosen), true]] as [$services, $auto]) {
            foreach ($services as $service) {
                $service->loadMissing('category');
                $rows[] = [
                    'id' => $service->id,
                    'code' => $service->code,
                    'name' => $service->name,
                    'department' => $service->isPackage() ? 'Package' : (string) $service->category?->name,
                    'rate' => $service->price,
                    'auto' => $auto,
                ];
            }
        }

        return $this->lineCache = $rows;
    }

    /** @return array{subtotal: Money, discount: Money, net: Money, paid: Money, due: Money, change: Money} */
    public function figures(): array
    {
        if ($this->figures !== null) {
            return $this->figures;
        }

        $subtotal = Money::sum(...array_map(fn (array $line): Money => $line['rate'], $this->lines()));

        try {
            $discount = app(DiscountRules::class)->amount($subtotal, $this->discountSource ?: null, $this->discountValue());
        } catch (FrontDeskException) {
            $discount = Money::zero();
        }

        $net = $subtotal->minus($discount);
        $paid = $this->money($this->paid) ?? Money::zero();

        return $this->figures = [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'net' => $net,
            'paid' => $paid,
            'due' => $paid->lessThan($net) ? $net->minus($paid) : Money::zero(),
            'change' => $paid->greaterThan($net) ? $paid->minus($net) : Money::zero(),
        ];
    }

    /** What was typed in the box the discount is made from. */
    private function discountValue(): string
    {
        return match ($this->discountSource) {
            'percent' => $this->discountPercent,
            'amount' => $this->discountAmount,
            default => '',
        };
    }

    /** Why the discount cannot be used yet, for showing next to the boxes. */
    public function discountProblem(): ?string
    {
        try {
            app(DiscountRules::class)->amount($this->figures()['subtotal'], $this->discountSource ?: null, $this->discountValue());
        } catch (FrontDeskException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** True when cash is chosen but this user has no open cash session to put it in. */
    public function needsCashSession(): bool
    {
        return $this->payMethod === PaymentMethod::Cash->value
            && $this->figures()['paid']->isPositive()
            && Auth::user() !== null
            && app(CashDrawer::class)->openSessionOf(Auth::user()) === null;
    }

    /** @return array<string, string> */
    public function doctors(): array
    {
        return ReferralDoctor::where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (ReferralDoctor $d): array => [$d->id => trim(($d->code ? $d->code.' · ' : '').$d->name)])->all();
    }

    /** @return array<int, string> every active service, for the pick list */
    public function catalog(): array
    {
        return Service::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'price'])
            ->mapWithKeys(fn (Service $s): array => [$s->id => "{$s->code} · {$s->name} · ".$s->price->formatted()])->all();
    }

    // Saving

    public function save(): void
    {
        $this->resetErrorBag();
        $this->validate($this->rules(), [], $this->attributes());

        if (! Cache::add("new-bill:{$this->billToken}", 1, 600)) {
            return; // the same form was already saved a moment ago
        }

        try {
            // Refuse a discount that is not allowed before the patient is registered,
            // so a refused bill does not leave a patient behind.
            $this->checkDiscount();

            $patient = $this->resolvePatient();
            $order = app(PlaceOrder::class)->handle(
                $patient,
                app(CurrentBranch::class)->get(),
                $this->serviceIds,
                [
                    'referral_doctor_id' => $this->doctorId,
                    'notes' => $this->notes,
                    'discount_type' => $this->discountSource ?: null,
                    'discount_value' => $this->discountValue(),
                    'discount_reason' => $this->discountReason,
                    'delivery_at' => $this->deliveryAt ?: null,
                ],
            );
        } catch (FrontDeskException|InvalidArgumentException $e) {
            Cache::forget("new-bill:{$this->billToken}");
            Notification::make()->danger()->title('Bill not saved')->body($e->getMessage())->persistent()->send();

            return;
        } catch (AuthorizationException) {
            Cache::forget("new-bill:{$this->billToken}");
            Notification::make()->danger()->title('Bill not saved')->body('You do not have permission to do this.')->send();

            return;
        }

        $invoice = $order->invoice;
        $paymentNote = $this->takePayment($invoice);
        $invoice->refresh();

        $links = $this->printLinks($order, $invoice->public_id);

        $this->last = [
            'invoice' => $invoice->public_id,
            'links' => array_map(fn (array $link): array => ['key' => $link['key'], 'label' => $link['label'], 'url' => $link['url']], array_slice($links, 3)),
            'no' => $invoice->invoice_no,
            'patient' => $patient->name.' ('.$patient->patient_code.')',
            'net' => $invoice->total->formatted(),
            'paid' => $invoice->paid_total->formatted(),
            'due' => $invoice->due_amount->isPositive() ? $invoice->due_amount->formatted() : '0.00',
            'change' => $this->figures()['change']->isPositive() ? $this->figures()['change']->formatted() : '',
            'note' => $paymentNote ?? '',
        ];

        Notification::make()->success()->title("Bill {$invoice->invoice_no} saved")->send();
        $this->blank();

        // What happens now is the clinic's choice (Front desk settings).
        $mode = app(Settings::class)->get(Permissions::SETTING_AFTER_SAVE, 'ask');

        if ($mode === 'ask') {
            $this->printPanel = true;
            $this->dispatch('bill-focus', target: 'printMain'); // so Enter prints
        } else {
            if ($mode === 'invoice') {
                $this->dispatch('bill-print', url: $links[0]['url']);
            }

            $this->dispatch('bill-focus', target: 'search');
        }
    }

    /** Close the print options and carry on with the next patient. */
    public function closePrint(): void
    {
        $this->printPanel = false;
        $this->dispatch('bill-focus', target: 'search');
    }

    /**
     * What can be printed for the saved bill: the invoice in three ways, then
     * whatever other modules add (the laboratory's sample labels).
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    private function printLinks(Order $order, string $invoicePublicId): array
    {
        $invoice = route('frontdesk.print.invoice', $invoicePublicId);

        return [
            ['key' => 'invoice', 'label' => 'Invoice', 'url' => $invoice.'?copy=both'],
            ['key' => 'patient', 'label' => 'Patient copy only', 'url' => $invoice],
            ['key' => 'office', 'label' => 'Office copy only', 'url' => $invoice.'?copy=office'],
            ...app(PrintLinks::class)->for($order),
        ];
    }

    /** The same discount checks PlaceOrder makes, run early. @throws FrontDeskException */
    private function checkDiscount(): void
    {
        $subtotal = $this->figures()['subtotal'];
        $discount = app(DiscountRules::class)->amount($subtotal, $this->discountSource ?: null, $this->discountValue());

        app(DiscountRules::class)->authorize(Auth::user(), app(CurrentBranch::class)->get(), $subtotal, $discount, $this->discountReason);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $rules = [
            'serviceIds' => ['required', 'array', 'min:1'],
            'discountPercent' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'discountAmount' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'paid' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'deliveryAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'max:1000'],
            'doctorId' => ['nullable', 'integer'],
        ];

        if ($this->patientId === null) {
            $rules += [
                'name' => ['required', 'string', 'max:255'],
                'mobile' => ['required', 'string', 'max:30'],
                'sex' => ['required', 'in:male,female,other'],
                'age' => ['required', 'integer', 'min:0', 'max:130'],
                'ageUnit' => ['required', 'in:Y,M,D'],
                'address' => ['nullable', 'string', 'max:255'],
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'serviceIds' => 'tests', 'discountPercent' => 'discount %', 'discountAmount' => 'discount Tk', 'paid' => 'paid amount', 'deliveryAt' => 'delivery time',
            'name' => 'name', 'mobile' => 'mobile', 'age' => 'age', 'sex' => 'sex',
        ];
    }

    /** The chosen patient, or a new one registered from the typed details. */
    private function resolvePatient(): Patient
    {
        if ($this->patientId !== null) {
            return Patient::findOrFail($this->patientId);
        }

        $data = ['name' => $this->name, 'sex' => $this->sex, 'phone' => $this->mobile, 'address' => $this->address];
        $data[['Y' => 'age_years', 'M' => 'age_months', 'D' => 'age_days'][$this->ageUnit]] = (int) $this->age;

        $patient = app(SavePatient::class)->register($data, app(CurrentBranch::class)->get());

        // If the bill itself is refused (a discount over the limit, say), the
        // patient is already registered; the next try must reuse them.
        $this->patientId = $patient->id;
        $this->patientCode = $patient->patient_code;

        return $patient;
    }

    /** The payment goes in after the bill. A refusal leaves the bill standing and says why. */
    private function takePayment(Invoice $invoice): ?string
    {
        $net = $invoice->total;
        $paid = $this->money($this->paid) ?? Money::zero();
        $amount = $paid->greaterThan($net) ? $net : $paid; // the rest is change handed back

        if (! $amount->isPositive()) {
            return null;
        }

        try {
            app(RecordPayment::class)->handle($invoice, $amount, PaymentMethod::from($this->payMethod), $this->payReference ?: null, $this->paymentKey);
        } catch (FrontDeskException|AuthorizationException $e) {
            $message = $e instanceof AuthorizationException ? 'You may not take payments.' : $e->getMessage();
            Notification::make()->warning()->title('Bill saved, but the payment was not recorded')->body($message.' Take the payment on the order screen.')->persistent()->send();

            return 'Payment not recorded: '.$message;
        }

        return null;
    }

    private function money(string $value): ?Money
    {
        try {
            return trim($value) === '' ? null : Money::fromDecimal(trim($value));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  iterable<Patient>  $patients
     * @return list<array{id: int, code: string, name: string, age: string, sex: string, phone: string}>
     */
    private function suggest(iterable $patients): array
    {
        $list = [];

        foreach ($patients as $patient) {
            $list[] = [
                'id' => $patient->id,
                'code' => $patient->patient_code,
                'name' => $patient->name,
                'age' => $patient->ageLabel(),
                'sex' => ucfirst($patient->sex->value),
                'phone' => (string) $patient->phone,
            ];
        }

        return $list;
    }

    public function mayCreatePatients(): bool
    {
        return Gate::allows(CorePermissions::PATIENTS_CREATE);
    }
}
