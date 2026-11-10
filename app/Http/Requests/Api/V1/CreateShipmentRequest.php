<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesShipmentParts;
use App\Couriers\AccountResolver;
use App\Couriers\Spx\SpxFieldLimits;
use App\Couriers\Spx\SpxFulfillment;
use App\Shipments\CourierFormFields;
use App\Api\ApiError;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** POST /shipments. Authorization is the signed request itself. */
class CreateShipmentRequest extends FormRequest
{
    use ValidatesShipmentParts;

    private const METADATA_MAX_KEYS = 20;

    private const METADATA_MAX_BYTES = 4096;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['nullable', ...$this->money(), 'min:0'];

        return $this->accountRules() + [
            'client_reference' => ['required', 'string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'order_label' => ['nullable', 'string', 'max:64'],
            'submit' => ['sometimes', 'boolean'],
            'method' => ['nullable', 'string', 'in:pickup,dropoff'],
            'pickup_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', $this->lbcPickupDate()],
            'pickup_slot' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:64'],
            'insure' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.price' => $money,
            'items.*.weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
            'total' => $money,
            'subtotal' => $money,
            'note' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array', 'max:'.self::METADATA_MAX_KEYS, $this->flatMetadata()],
        ] + $this->parcelRules() + $this->paymentRules() + $this->pickupRules() + $this->recipientRules();
    }

    /** SPX's field limits, for an SPX account: see SpxFieldLimits. A pickup_slot that is not an SPX slot is refused too. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->courierOfAccount() !== 'spx') {
                return;
            }

            $recipient = $this->input('recipient');
            $pickup = $this->input('pickup');
            $note = $this->input('note');
            $messages = [
                'recipient_name' => ['recipient.first_name', 'For SPX, the first_name and last_name together may not be longer than '.SpxFieldLimits::NAME_MAX.' characters.'],
                'recipient_address' => ['recipient.address_1', 'For SPX, the address_1 and address_2 together may not be longer than '.SpxFieldLimits::DETAIL_ADDRESS_MAX.' characters.'],
                'pickup_name' => ['pickup.contact_name', 'For SPX, the contact_name may not be longer than '.SpxFieldLimits::NAME_MAX.' characters.'],
                'pickup_address' => ['pickup.address_1', 'For SPX, the address_1 and address_2 together may not be longer than '.SpxFieldLimits::DETAIL_ADDRESS_MAX.' characters.'],
                'note' => ['note', 'For SPX, the note may not be longer than '.SpxFieldLimits::INSTRUCTION_MAX.' characters.'],
            ];
            $problems = SpxFieldLimits::problems(is_array($recipient) ? $recipient : null, is_array($pickup) ? $pickup : null, is_string($note) ? $note : null);
            foreach ($problems as $problem) {
                [$field, $message] = $messages[$problem];
                if (! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, $message);
                }
            }

            $slot = $this->input('pickup_slot');
            if (is_string($slot) && $slot !== '' && $this->input('method') !== 'dropoff' && SpxFulfillment::describeSlot($slot) === null
                && ! $validator->errors()->has('pickup_slot')) {
                $validator->errors()->add('pickup_slot', 'For SPX, the pickup_slot must be a value from POST /pickup-slots.');
            }
        }];
    }

    /** The courier of the account the request names; null when it names none that exists (its own rule says so). */
    private function courierOfAccount(): ?string
    {
        try {
            $id = $this->input('account_id');
            $courier = $this->input('courier');

            return AccountResolver::resolve(is_string($id) ? $id : null, is_string($courier) ? $courier : null)->courier;
        } catch (ApiError) {
            return null;
        }
    }

    /** For an LBC account the date must be one LBC takes: a business day from the first bookable one, within the range the form offers. */
    private function lbcPickupDate(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || $value === '') {
                return;
            }
            try {
                $account = AccountResolver::resolve($this->input('account_id'), $this->input('courier'));
            } catch (ApiError) {
                return;
            }
            if ($account->courier !== 'lbc' || in_array($value, array_column(CourierFormFields::lbcDates(), 'value'), true)) {
                return;
            }
            $dates = CourierFormFields::lbcDates();
            $fail('The :attribute must be a Monday to Saturday between '.$dates[0]['value'].' and '.end($dates)['value'].' for LBC.');
        };
    }

    /** Metadata is echoed back on every answer and webhook: flat string keys, scalar values, small. */
    private function flatMetadata(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                if (! is_string($key) || $key === '' || strlen($key) > 40 || ! (is_scalar($item) || $item === null)) {
                    $fail('The :attribute must be an object of up to 40-character keys with string, number, boolean or null values.');

                    return;
                }
            }
            if (strlen((string) json_encode($value)) > self::METADATA_MAX_BYTES) {
                $fail('The :attribute may not be larger than '.self::METADATA_MAX_BYTES.' bytes.');
            }
        };
    }
}
