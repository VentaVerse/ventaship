<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesShipmentParts;
use App\Shipments\Quotes\AllCouriersQuote;
use Illuminate\Foundation\Http\FormRequest;

/** POST /quotes: the fee one account would charge for one parcel, or (`couriers: "all"`) every active account. */
class QuoteRequest extends FormRequest
{
    use ValidatesShipmentParts;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = $this->accountRules();
        // `couriers: "all"` asks every active account at once, instead of one account_id or courier.
        $rules['account_id'] = ['nullable', 'string', 'max:30', 'required_without_all:courier,couriers'];
        $rules['courier'] = ['nullable', 'string', 'max:16', 'required_without_all:account_id,couriers'];
        $rules['couriers'] = ['nullable', 'string', 'in:all', 'prohibits:account_id,courier'];
        $rules['budget_ms'] = ['nullable', 'integer', 'min:'.AllCouriersQuote::MIN_BUDGET_MS, 'max:'.AllCouriersQuote::MAX_BUDGET_MS];

        return $rules + $this->addressRules('pickup') + [
            'pickup' => ['required', 'array'],
            'recipient' => ['required', 'array'],
            'service' => ['nullable', 'string', 'max:64'],
            'insure' => ['sometimes', 'boolean'],
        ] + $this->optionalPickupContactRules() + $this->optionalRecipientContactRules('recipient') + $this->addressRules('recipient') + $this->parcelRules() + $this->paymentRules();
    }
}
