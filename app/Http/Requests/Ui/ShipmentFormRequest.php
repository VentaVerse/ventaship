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
 * money fields are derived from the items.
 */
class ShipmentFormRequest extends FormRequest
{
    use ValidatesShipmentParts;

    private const REFERENCE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * COD and the declared value are never typed in: COD collects the items total, and Insure declares it (else an
     * explicit 0, since QuadX insures at the total when the value is missing). Posted amounts are overwritten.
     */
    protected function prepareForValidation(): void
    {
        $payment = (array) $this->input('payment', []);
        $isCod = ($payment['method'] ?? null) === 'cod';
        $insure = $this->boolean('insure');
        $total = self::itemsTotal((array) $this->input('items', []));

        $payment['cod_amount'] = $isCod && bccomp($total, '0', 2) > 0 ? $total : null;
        $payment['declared_value'] = $insure ? $total : '0';
        $payment['insure'] = $insure;

        $account = (string) $this->input('account_id');
        $reference = trim((string) $this->input('client_reference'));

        $this->merge([
            'payment' => $payment,
            'account_id' => $account === '' ? null : $account,
            'client_reference' => $reference === '' ? null : $reference,
            'insure' => $insure,
            'submit' => true,
        ]);
    }

    /**
     * Σ quantity × price as a 2-decimal string. Rows whose quantity or price is not a plain number count as 0; the
     * item rules report them.
     *
     * @param  array<mixed>  $items
     */
    public static function itemsTotal(array $items): string
    {
        $total = '0.00';
        foreach ($items as $item) {
            $qty = trim((string) (is_array($item) ? ($item['quantity'] ?? '') : ''));
            $price = trim((string) (is_array($item) ? ($item['price'] ?? '') : ''));
            if (preg_match('/^\d{1,6}$/', $qty) && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $price)) {
                $total = bcadd($total, bcmul($qty, $price, 2), 2);
            }
        }

        return $total;
    }

    /** A posted address whose codes do not chain, or whose names are not the codes' names, is refused. */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (['recipient', 'pickup'] as $prefix) {
                $address = $this->input($prefix);
                if (is_array($address) && ! app(PhAddress::class)->matchesPosted($address)) {
                    $validator->errors()->add("{$prefix}.state", 'Choose from the list.');
                }
            }
        }];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['nullable', ...$this->money(), 'min:0'];

        return [
            'account_id' => ['required', 'string', 'max:30'],
            'client_reference' => ['nullable', 'string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'service' => ['nullable', 'string', 'max:64'],
            'insure' => ['boolean'],
            'payment.insure' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.price' => $money,
        ] + $this->parcelRules() + $this->paymentRules() + $this->pickupRules() + $this->recipientRules()
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
            'payment.cod_amount.required_if' => 'Add item prices.',
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
