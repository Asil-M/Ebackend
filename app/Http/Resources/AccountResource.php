<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $team = match ($this->role) {
            UserRole::SosTeam => $this->sosTeam,
            UserRole::DonationTeam => $this->donationTeam,
            default => null,
        };

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'role' => $this->role,
            'team_id' => $team?->id,
            'service_area' => $team?->service_area,
            // Normal users and admins do not have an activation state.
            'is_active' => $team?->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
