<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreTeamAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The admin middleware performs the authorization check.
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone_number' => [
                'required',
                'string',
                'max:30',
                'unique:users,phone_number',
            ],
            'password' => ['required', 'confirmed', Password::min(8)],
            'role' => ['required', Rule::in(['sos_team', 'donation_team'])],
            'service_area' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
