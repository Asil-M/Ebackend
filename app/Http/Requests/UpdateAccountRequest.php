<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The admin middleware performs the authorization check.
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone_number' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::unique('users', 'phone_number')->ignore($userId),
            ],
            'password' => ['sometimes', 'required', 'confirmed', Password::min(8)],
            'service_area' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
