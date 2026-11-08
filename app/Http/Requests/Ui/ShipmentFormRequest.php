<?php

namespace App\Http\Requests\Ui;

use App\Http\Requests\Api\V1\Concerns\ValidatesShipmentParts;
use App\Models\Shipment;
use App\Support\PhAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The Add shipment form. It accepts the API's input shape (so ShipmentCreator takes it unchanged) with the form's
 * differences: the order reference is optional and generated when blank, the shipment is always submitted, and the
 * money fields are derived from the Value.
 */
class ShipmentFormRequest extends FormRequest
{
    use ValidatesShipmentParts;

    private const REFERENCE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /** What an item quantity and the Value may look like; resources/js/shipmentForm.js uses the same patterns. */
    private const QTY_PATTERN = '/^\d{1,6}$/';

    private const VALUE_PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The Value is the one amount the admin types: it is the shipment total, what COD collects and, with Insure, the
     * declared value (else an explicit 0, since QuadX insures at the total when the value is missing). Items carry no
     * price. Posted totals, COD, declared values and item prices are overwritten.
     */
    protected function prepareForValidation(): void
    {
        $payment = (array) $this->input('payment', []);
        $isCod = ($payment['method'] ?? null) === 'cod';
        $insure = $this->boolean('insure');
        $items = self::normaliseItems($this->input('items'));
        $value = self::normaliseValue($this->input('value'));
        $valid = is_string($value) && preg_match(self::VALUE_PATTERN, $value) === 1 && bccomp($value, '0', 2) > 0;

        $payment['cod_amount'] = $isCod && $valid ? $value : null;
        $payment['declared_value'] = $insure && $valid ? $value : '0';
        $payment['insure'] = $insure;

        $account = (string) $this->input('account_id');
        $reference = trim((string) $this->input('client_reference'));

        $this->merge([
            'items' => $items,
            'value' => $value,
            'total' => $valid ? $value : null,
            'payment' => $payment,
            'account_id' => $account === '' ? null : $account,
            'client_reference' => $reference === '' ? null : $reference,
            'insure' => $insure,
            'submit' => true,
        ]);
    }

    /** Quantities in the one shape the rules read, and every item price dropped. */
    public static function normaliseItems(mixed $items): mixed
    {
        if (! is_array($items)) {
            return $items;
        }
        foreach ($items as $n => $item) {
            if (! is_array($item)) {
                continue;
            }
            if (is_string($item['quantity'] ?? null)) {
                $item['quantity'] = (string) preg_replace('/^\+/', '', trim($item['quantity']));
            }
            $item['price'] = null;
            $items[$n] = $item;
        }

        return $items;
    }

    /** The Value trimmed, without a leading "+", and ".5" / "5." written out as "0.50" / "5.00". Anything else is left for the rules. */
    public static function normaliseValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = (string) preg_replace('/^\+/', '', trim($value));
        if (preg_match('/^(\d*)\.(\d{0,2})$/', $value, $m) && $m[1].$m[2] !== '') {
            return ($m[1] === '' ? '0' : $m[1]).'.'.str_pad($m[2], 2, '0');
        }

        return $value;
    }

    /** A posted address whose codes do not chain, or whose names are not the codes' names, is refused. */
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
        }];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $payment = $this->paymentRules();
        // COD collects the Value, which has its own rule and message.
        $payment['payment.cod_amount'] = ['nullable', ...$this->money(), 'gt:0'];

        return [
            'value' => ['required', 'string', 'regex:'.self::VALUE_PATTERN, 'numeric', 'gt:0', 'max:9999999999.99'],
            'total' => ['nullable', ...$this->money(), 'gt:0'],
            'account_id' => ['required', 'string', 'max:30'],
            'client_reference' => ['nullable', 'string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'service' => ['nullable', 'string', 'max:64'],
            'insure' => ['boolean'],
            'payment.insure' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000', 'regex:'.self::QTY_PATTERN],
        ] + $this->parcelRules() + $payment + $this->pickupRules() + $this->recipientRules()
            + ['parcel.weight_kg' => ['required', 'numeric', 'gt:0', 'max:1000']];
    }

    /** The validated input, with the order reference generated when it was left blank (unless an edit keeps its own). @return array<string, mixed> */
    public function shipmentData(bool $generateReference = true): array
    {
        $data = $this->validated();
        if ($generateReference) {
            $data['client_reference'] ??= self::generateReference();
        }

        return $data;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'value.required' => 'Enter the parcel value.',
            'value.string' => 'Enter the parcel value.',
            'value.regex' => 'Enter the parcel value.',
            'value.numeric' => 'Enter the parcel value.',
            'value.gt' => 'Enter the parcel value.',
            'value.max' => 'Enter the parcel value.',
            'items.*.quantity.regex' => 'Whole number.',
            '*.required' => 'Required.',
            '*.required_if' => 'Required.',
            '*.max' => 'Too long.',
            '*.string' => 'Not valid.',
            '*.in' => 'Not valid.',
            '*.regex' => 'Not valid.',
            '*.email' => 'Not a valid email.',
            '*.numeric' => 'Not a number.',
            '*.integer' => 'Not a whole number.',
            '*.decimal' => 'At most 2 decimals.',
            '*.gt' => 'Must be more than 0.',
            '*.min' => 'Too small.',
            '*.prohibited_unless' => 'Not valid.',
        ];
    }

    /** `M-{Ymd}-{6 random A-Z0-9}`, unique among the active manual references. */
    public static function generateReference(): string
    {
        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= self::REFERENCE_CHARS[random_int(0, strlen(self::REFERENCE_CHARS) - 1)];
            }
            $reference = 'M-'.now()->format('Ymd').'-'.$suffix;
        } while (Shipment::query()->whereNull('client_app_id')->where('active_reference', $reference)->exists());

        return $reference;
    }
}
