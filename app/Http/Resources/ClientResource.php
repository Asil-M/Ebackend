<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'blood_type' => $this->blood_type,
            'emergency_contact_number' => $this->emergency_contact_number,
            'emergency_contact_relation' => $this->emergency_contact_relation,
            'allergies' => $this->allergies,
            'medical_conditions' => $this->medical_conditions,
        ];
    }
}
