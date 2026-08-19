<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['request', 'donation'])],
            'category' => [
                'required',
                Rule::in(['blood', 'money', 'clothes', 'food', 'medicine', 'other']),
            ],
            'additional_note' => ['nullable', 'string'],
            'location' => [
                'required',
                Rule::in([
                    'beirut',
                    'tripoli',
                    'south',
                    'baalbek',
                    'bekaa',
                    'mount_lebanon',
                    'nabatieh',
                ]),
            ],
            'details' => ['required', 'array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $details = $this->input('details', []);

                $requiredFields = match ($this->category) {
                    'blood' => ['blood_type', 'units'],
                    'money' => ['amount', 'currency'],
                    'clothes' => ['clothing_type', 'gender', 'size', 'quantity'],
                    'food' => ['food_type', 'quantity'],
                    'medicine' => ['medicine_name', 'dose', 'quantity'],
                    'other' => ['custom_category'],
                    default => [],
                };

                if (
                    $this->type === 'donation'
                    && in_array($this->category, ['food', 'medicine'], true)
                ) {
                    $requiredFields[] = 'expiration_date';
                }

                foreach ($requiredFields as $field) {
                    if (! isset($details[$field]) || $details[$field] === '') {
                        $validator->errors()->add(
                            "details.$field",
                            'This field is required.'
                        );
                    }
                }

                $quantityField = match ($this->category) {
                    'blood' => 'units',
                    'money' => 'amount',
                    'clothes', 'food', 'medicine' => 'quantity',
                    default => null,
                };

                if ($quantityField !== null) {
                    $quantity = $details[$quantityField] ?? null;
                    if (! is_numeric($quantity) || (float) $quantity <= 0) {
                        $validator->errors()->add(
                            "details.$quantityField",
                            'Quantity must be a positive number.'
                        );
                    } elseif (
                        $this->category !== 'money'
                        && filter_var($quantity, FILTER_VALIDATE_INT) === false
                    ) {
                        $validator->errors()->add(
                            "details.$quantityField",
                            'Quantity must be a whole number.'
                        );
                    }
                }

                if (
                    $this->category === 'money'
                    && ! in_array($details['currency'] ?? null, ['USD', 'LBP'], true)
                ) {
                    $validator->errors()->add(
                        'details.currency',
                        'Currency must be USD or LBP.'
                    );
                }

                if (
                    $this->type === 'request'
                    && in_array($this->category, ['food', 'medicine'], true)
                    && array_key_exists('expiration_date', $details)
                ) {
                    $validator->errors()->add(
                        'details.expiration_date',
                        'Expiration date is only allowed for donation offers.'
                    );
                }

                if (
                    $this->category === 'clothes'
                    && array_key_exists('condition', $details)
                ) {
                    $validator->errors()->add(
                        'details.condition',
                        'Clothes condition is not accepted.'
                    );
                }
            },
        ];
    }
}
