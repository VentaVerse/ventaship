<?php

namespace App\Http\Requests\Ui;

use App\Couriers\Spx\SpxFieldLimits;
use App\Http\Requests\Api\V1\Concerns\ValidatesShipmentParts;
use App\Models\CourierAccount;
use App\Models\Customer;
use App\Models\PickupAddress;
use App\Models\Shipment;
use App\Shipments\CourierFormFields;
use App\Rules\PhMobile;
use App\Support\PhAddress;
use App\Support\PlainMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Add shipment form. It accepts the API's input shape (so ShipmentCreator takes it unchanged) with the form's
 * differences: the order reference is optional (blank, a new shipment's order number is its courier reference; an
 * edit keeps its own), the shipment is always submitted, and the
 * money fields are derived from the Value. The pickup is never posted: `pickup_address_id` names a saved pickup address
 * (or, on an edit, `current`: the shipment's own snapshot), and that address is copied into `pickup` here. The items
 * are one text field, `item`, stored as a single item of quantity 1; posted `items` are ignored.
 */
class ShipmentFormRequest extends FormRequest
{
    use ValidatesShipmentParts;

    /** What the Value may look like; resources/js/shipmentForm.js uses the same pattern. */
    private const VALUE_PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';

    private const SIZE_KEYS = ['length_cm', 'width_cm', 'height_cm'];

    /** The chosen account, looked up once; its courier decides which courier fields are kept. */
    private ?CourierAccount $courierAccount = null;

    /** The pickup `pickup_address_id` resolved to, in the shipment `pickup` shape; null when it named none. */
    private ?array $chosenPickup = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The Value is the one amount the admin types: it is the shipment total, what COD collects and, with Insure, the
     * declared value (else an explicit 0, since QuadX insures at the total when the value is missing). The item carries
     * no price. Posted totals, COD, declared values and items are overwritten.
     */
    protected function prepareForValidation(): void
    {
        $payment = (array) $this->input('payment', []);
        $isCod = ($payment['method'] ?? null) === 'cod';
        $insure = $this->boolean('insure');
        $item = $this->input('item');
        $value = self::normaliseValue($this->input('value'));
        $valid = is_string($value) && preg_match(self::VALUE_PATTERN, $value) === 1 && bccomp($value, '0', 2) > 0;

        $payment['cod_amount'] = $isCod && $valid ? $value : null;
        $payment['declared_value'] = $insure && $valid ? $value : '0';
        $payment['insure'] = $insure;

        $account = (string) $this->input('account_id');
        $reference = trim((string) $this->input('client_reference'));

        $this->courierAccount = $account === '' ? null : CourierAccount::query()->where('public_id', $account)->first();
        $this->chosenPickup = $this->resolvePickup($this->input('pickup_address_id'));

        $input = $this->all();
        unset($input['pickup'], $input['items']);
        if (is_array($input['recipient'] ?? null) && array_key_exists('phone', $input['recipient'])) {
            $input['recipient']['phone'] = PhMobile::canonical($input['recipient']['phone']) ?? $input['recipient']['phone'];
        }
        if ($this->chosenPickup !== null) {
            $input['pickup'] = $this->chosenPickup;
        }

        $this->replace($this->courierInput(array_merge($input, [
            'item' => is_string($item) ? trim($item) : $item,
            'value' => $value,
            'total' => $valid ? $value : null,
            'payment' => $payment,
            'account_id' => $account === '' ? null : $account,
            'client_reference' => $reference === '' ? null : $reference,
            'insure' => $insure,
            'submit' => true,
        ])));
    }

    /**
     * A saved pickup address by its public id, copied; or `current` on an edit: the shipment's own snapshot.
     *
     * @return array<string, mixed>|null
     */
    private function resolvePickup(mixed $id): ?array
    {
        if (! is_string($id) || $id === '') {
            return null;
        }
        if ($id === 'current') {
            $shipment = $this->route('shipment');
            $own = $shipment instanceof Shipment ? $shipment->pickup : null;

            return is_array($own) && $own !== [] ? $own : null;
        }

        return PickupAddress::query()->where('public_id', $id)->first()?->toPickup();
    }

    private function courier(): string
    {
        return (string) $this->courierAccount?->courier;
    }

    /**
     * Only the chosen courier's fields are kept; the others are dropped before validation, so they are neither
     * validated nor stored. Who pays shipping, the SPX item category and the SPX handover fall back to their defaults.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function courierInput(array $input): array
    {
        $courier = $this->courier();
        $parcel = is_array($input['parcel'] ?? null) ? $input['parcel'] : [];
        $type = is_string($parcel['type'] ?? null) ? trim($parcel['type']) : ($parcel['type'] ?? null);
        $parcel['type'] = $type === '' ? null : $type;

        $keep = ['type'];
        if (in_array($courier, ['spx', 'lbc'], true)) {
            $keep[] = 'weight_kg';
        }
        $size = CourierFormFields::sizeRule($courier, is_string($parcel['type']) ? $parcel['type'] : null);
        if ($size !== null) {
            array_push($keep, ...self::SIZE_KEYS);
        }
        if ($courier === 'spx') {
            $keep[] = 'category';
            $parcel['category'] = self::blank($parcel['category'] ?? null) ? CourierFormFields::DEFAULT_CATEGORY : $parcel['category'];
        }
        $parcel = array_intersect_key($parcel, array_flip($keep));
        // An optional size left blank is no size.
        if ($size === 'optional' && collect(self::SIZE_KEYS)->every(fn ($k) => self::blank($parcel[$k] ?? null))) {
            $parcel = array_diff_key($parcel, array_flip(self::SIZE_KEYS));
        }
        $input['parcel'] = $parcel;

        $payment = is_array($input['payment'] ?? null) ? $input['payment'] : [];
        $paysDefault = CourierFormFields::defaultShippingPayment($courier);
        if ($paysDefault === null) {
            unset($payment['shipping_payment']);
        } elseif (self::blank($payment['shipping_payment'] ?? null)) {
            $payment['shipping_payment'] = $paysDefault;
        }
        $input['payment'] = $payment;

        if ($courier !== 'quadx') {
            unset($input['service']);
        }
        if (! in_array($courier, ['quadx', 'spx'], true)) {
            unset($input['note']);
        } elseif (self::blank($input['note'] ?? null)) {
            $input['note'] = null;
        }
        if ($courier === 'spx') {
            $input['handover'] = self::blank($input['handover'] ?? null) ? 'dropoff' : $input['handover'];
            if (in_array($input['handover'], ['pickup', 'dropoff'], true)) {
                $input['method'] = $input['handover'];
            }
        } else {
            unset($input['handover']);
        }
        if ($courier !== 'spx' || ($input['handover'] ?? null) !== 'pickup') {
            unset($input['pickup_slot']);
        }
        if ($courier !== 'lbc') {
            unset($input['pickup_date']);
        }

        return $input;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /** @return array<string, mixed> the rules for the chosen courier's own fields */
    private function courierRules(): array
    {
        $courier = $this->courier();
        if ($this->courierAccount === null) {
            return [];
        }

        $number = ['numeric', 'gt:0', 'max:1000'];
        $rules = ['parcel.type' => ['required', 'string', Rule::in(CourierFormFields::parcelValues($this->courierAccount))]];
        if (in_array($courier, ['spx', 'lbc'], true)) {
            $rules['parcel.weight_kg'] = ['required', ...$number];
        }
        $type = $this->input('parcel.type');
        $size = CourierFormFields::sizeRule($courier, is_string($type) ? $type : null);
        foreach (self::SIZE_KEYS as $key) {
            $others = implode(',', array_map(fn ($k) => "parcel.{$k}", array_diff(self::SIZE_KEYS, [$key])));
            if ($size === 'required') {
                $rules["parcel.{$key}"] = ['required', ...$number];
            } elseif ($size === 'optional') {
                $rules["parcel.{$key}"] = ['nullable', "required_with:{$others}", ...$number];
            }
        }
        if (isset(CourierFormFields::SHIPPING_PAYMENT[$courier])) {
            $rules['payment.shipping_payment'] = ['required', 'string', Rule::in(array_keys(CourierFormFields::SHIPPING_PAYMENT[$courier]))];
        }
        if (in_array($courier, ['quadx', 'spx'], true)) {
            $rules['note'] = ['nullable', 'string', 'max:'.CourierFormFields::NOTE_MAX];
        }
        if ($courier === 'spx') {
            $rules['parcel.category'] = ['required', 'string', Rule::in(CourierFormFields::categories())];
            $rules['method'] = ['nullable', 'string', 'in:pickup,dropoff'];
            $rules['handover'] = ['required', 'string', Rule::in(array_keys(CourierFormFields::HANDOVER))];
            $rules['pickup_slot'] = ['required_if:handover,pickup', 'nullable', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (CourierFormFields::spxSlotTime($value) === null) {
                        $fail('Choose a time.');
                    }
                }];
        }
        if ($courier === 'lbc') {
            $rules['pickup_date'] = ['required', 'string', Rule::in(array_column(CourierFormFields::lbcDates(), 'value'))];
        }

        return $rules;
    }

    /** The Value trimmed, without a leading "+", and ".5" / "5." written out as "0.50" / "5.00". Anything else is left for the rules. */
    public static function normaliseValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return '';
        }
        $value = (string) preg_replace('/^\+/', '', trim($value));
        if (preg_match('/^(\d*)\.(\d{0,2})$/', $value, $m) && $m[1].$m[2] !== '') {
            return ($m[1] === '' ? '0' : $m[1]).'.'.str_pad($m[2], 2, '0');
        }

        return $value;
    }

    /**
     * A posted address whose codes do not chain, or whose names are not the codes' names, is refused. For SPX, so are
     * values longer than SPX takes (SpxFieldLimits); the pickup's are named on the pickup address, its only field.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (['recipient', 'pickup'] as $prefix) {
                $address = $this->input($prefix);
                $field = is_array($address) ? app(PhAddress::class)->postedMismatch($address) : null;
                if ($field !== null) {
                    $validator->errors()->add("{$prefix}.{$field}", 'Choose from the list.');
                }
            }

            if ($this->courier() !== 'spx') {
                return;
            }
            $messages = [
                'recipient_name' => ['recipient.first_name', 'First and last name: '.SpxFieldLimits::NAME_MAX.' characters at most for SPX.'],
                'recipient_address' => ['recipient.address_1', SpxFieldLimits::DETAIL_ADDRESS_MAX.' characters at most for SPX.'],
                'pickup_name' => ['pickup_address_id', 'Contact name: '.SpxFieldLimits::NAME_MAX.' characters at most for SPX.'],
                'pickup_address' => ['pickup_address_id', 'Street: '.SpxFieldLimits::DETAIL_ADDRESS_MAX.' characters at most for SPX.'],
                'note' => ['note', SpxFieldLimits::INSTRUCTION_MAX.' characters at most for SPX.'],
            ];
            $recipient = $this->input('recipient');
            $pickup = $this->input('pickup');
            foreach (SpxFieldLimits::problems(is_array($recipient) ? $recipient : null, is_array($pickup) ? $pickup : null, $this->input('note')) as $problem) {
                [$field, $message] = $messages[$problem];
                if (! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $payment = $this->paymentRules();
        // COD collects the Value, which has its own rule and message.
        $payment['payment.cod_amount'] = ['nullable', ...$this->money(), 'gt:0'];

        $rules = $this->courierRules() + [
            'value' => ['required', 'string', 'regex:'.self::VALUE_PATTERN, 'numeric', 'gt:0', 'max:9999999999.99'],
            'total' => ['nullable', ...$this->money(), 'gt:0'],
            'account_id' => ['required', 'string', 'max:30'],
            'client_reference' => ['nullable', 'string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'service' => ['nullable', 'string', 'max:64'],
            'insure' => ['boolean'],
            'payment.insure' => ['boolean'],
            'item' => ['required', 'string', 'max:255'],
            'customer_id' => ['nullable', 'string', 'max:64', Rule::exists('customers', 'public_id')],
            'save_customer' => ['boolean'],
            'pickup_address_id' => ['bail', 'required', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail) {
                if ($this->chosenPickup === null) {
                    $fail('Choose a pickup address.');
                }
            }],
        ] + $this->parcelRules() + $payment + ($this->chosenPickup !== null ? $this->pickupRules() : []) + $this->recipientRules();
        $rules['recipient.phone'] = ['required', 'string', new PhMobile];
        // Every courier delivers to a barangay; SPX matches on it. The API leaves it optional for its clients.
        $rules['recipient.barangay'] = ['required', 'string', 'max:255'];

        return $this->spxRules($rules);
    }

    /** SPX's own limits, checked inline; money and weights as strings, never floats. @param array<string, mixed> $rules */
    private function spxRules(array $rules): array
    {
        if ($this->courier() !== 'spx') {
            return $rules;
        }
        $num = fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{1,10}(\.\d{1,6})?$/', trim($v)) === 1 ? trim($v) : (is_int($v) ? (string) $v : null);
        $rules['value'][] = function (string $a, mixed $v, \Closure $fail) use ($num) {
            if (($this->input('payment.method') ?? null) !== 'cod' || ($n = $num($v)) === null) {
                return;
            }
            if (bccomp($n, bcadd($n, '0', 0), 2) !== 0) {
                $fail('SPX collects whole pesos only.');
            } elseif (bccomp($n, '50000', 2) > 0) {
                $fail('SPX collects up to ₱50,000.');
            }
        };
        $rules['parcel.weight_kg'][] = function (string $a, mixed $v, \Closure $fail) use ($num) {
            if (($n = $num($v)) !== null && bccomp($n, '50', 6) >= 0) {
                $fail('Under 50 kg.');
            }
        };
        foreach (self::SIZE_KEYS as $key) {
            $rules["parcel.{$key}"][] = function (string $a, mixed $v, \Closure $fail) use ($num) {
                if (($n = $num($v)) !== null && bccomp($n, '150', 6) > 0) {
                    $fail('150 cm at most.');
                }
            };
        }

        return $rules;
    }

    /**
     * The validated input in the API's shape: the item as the one item. A blank order reference stays null:
     * ShipmentCreator gives a new shipment its courier reference as its order number, and an edit keeps its own.
     *
     * @return array<string, mixed>
     */
    public function shipmentData(): array
    {
        $data = $this->validated();
        $data['items'] = [['name' => $data['item'], 'quantity' => 1, 'price' => null]];
        unset($data['item'], $data['pickup_address_id'], $data['customer_id'], $data['save_customer']);

        return $data;
    }

    /** The saved customer picked from the search, if any (validated to exist). */
    public function pickedCustomer(): ?Customer
    {
        $id = $this->validated('customer_id');

        return is_string($id) && $id !== '' ? Customer::query()->where('public_id', $id)->first() : null;
    }

    /**
     * Plain messages, never an attribute name (PlainMessages): the field's own label is right above its error.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $specific = [
            'recipient.state.required' => 'Choose a province.',
            'recipient.city.required' => 'Choose a city or municipality.',
            'recipient.barangay.required' => 'Choose a barangay.',
            'value.required' => 'Enter the parcel value.',
            'value.string' => 'Enter the parcel value.',
            'value.regex' => 'Enter the parcel value.',
            'value.numeric' => 'Enter the parcel value.',
            'value.gt' => 'Enter the parcel value.',
            'value.max' => 'Enter the parcel value.',
            'customer_id.exists' => 'Search again.',
            'pickup_address_id.required' => 'Choose a pickup address.',
            'pickup_address_id.string' => 'Choose a pickup address.',
            'pickup_address_id.max' => 'Choose a pickup address.',
            'parcel.type.required' => 'Choose the packaging.',
            'parcel.type.in' => 'Choose the packaging.',
            'pickup_slot.required_if' => 'Choose a time.',
            'pickup_date.required' => 'Choose a date.',
            'pickup_date.in' => 'Choose a date.',
        ];

        return PlainMessages::with($specific);
    }

    /**
     * The fields' labels, for any message that still names its field.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'account_id' => 'courier',
            'client_reference' => 'reference',
            'pickup_address_id' => 'pickup address',
            'customer_id' => 'customer',
            'recipient.first_name' => 'first name',
            'recipient.last_name' => 'last name',
            'recipient.phone' => 'phone',
            'recipient.email' => 'email',
            'recipient.address_1' => 'street',
            'recipient.address_2' => 'street',
            'recipient.state' => 'province',
            'recipient.city' => 'city or municipality',
            'recipient.district' => 'district',
            'recipient.barangay' => 'barangay',
            'recipient.postcode' => 'postcode',
            'parcel.type' => 'packaging',
            'parcel.weight_kg' => 'weight',
            'parcel.length_cm' => 'length',
            'parcel.width_cm' => 'width',
            'parcel.height_cm' => 'height',
            'parcel.category' => 'item category',
            'payment.method' => 'payment',
            'payment.shipping_payment' => 'who pays shipping',
            'payment.cod_amount' => 'value',
            'payment.declared_value' => 'value',
            'pickup_slot' => 'pickup time',
            'pickup_date' => 'pickup date',
            'note' => 'delivery note',
        ];
    }
}
