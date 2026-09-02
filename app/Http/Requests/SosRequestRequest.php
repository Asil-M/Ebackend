<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SosRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'relay_message_id' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::in(['ambulance', 'fire', 'police'])],
            'location_name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
