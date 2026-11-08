<?php

namespace App\Http\Requests\Ui;

use App\Http\Requests\Api\V1\Concerns\ValidatesShipmentParts;
use App\Models\Shipment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Add shipment form. It accepts the API's input shape (so ShipmentCreator takes it unchanged) with the form's
 * differences: the order reference is optional and generated when blank, and the shipment is always submitted.
 */
class ShipmentFormRequest extends FormRequest
{
    use ValidatesShipmentParts;

    private const REFERENCE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payment = (array) $this->input('payment', []);
        $isCod = ($payment['method'] ?? null) === 'cod';
        if (! $isCod) {
            $payment['cod_amount'] = null;
        }

        $account = (string) $this->input('account_id');
        $reference = trim((string) $this->input('client_reference'));

        $this->merge([
            'payment' => $payment,
            'account_id' => $account === '' ? null : $account,
            'client_reference' => $reference === '' ? null : $reference,
            'insure' => $this->boolean('insure'),
            'submit' => true,
        ]);
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
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.price' => $money,
        ] + $this->parcelRules() + $this->paymentRules() + $this->pickupRules() + $this->recipientRules()
            + ['parcel.weight_kg' => ['required', 'numeric', 'gt:0', 'max:1000']];
    }

    /** The validated input, with the order reference generated when it was left blank. @return array<string, mixed> */
    public function shipmentData(): array
    {
        $data = $this->validated();
        $data['client_reference'] ??= self::generateReference();

        return $data;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
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
