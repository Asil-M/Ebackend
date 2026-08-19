<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_of_birth' => ['required', 'date', 'before:today'],
            'blood_type' => ['required', 'string', 'max:10'],
            'emergency_contact_number' => ['required', 'string', 'max:30'],
            'emergency_contact_relation' => ['required', 'string', 'max:100'],
            'allergies' => ['nullable', 'string'],
            'medical_conditions' => ['nullable', 'string'],
        ];
    }
}
