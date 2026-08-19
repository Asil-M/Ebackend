<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchedDonationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'donation_team_id' => $this->donation_team_id,
            'request_donation' => new DonationResource(
                $this->whenLoaded('requestDonation')
            ),
            'offered_donation' => new DonationResource(
                $this->whenLoaded('offeredDonation')
            ),
            'matched_quantity' => $this->matched_quantity,
            'status' => $this->status,
            'matched_at' => $this->matched_at,
            'accepted_at' => $this->accepted_at,
            'rejected_at' => $this->rejected_at,
        ];
    }
}
