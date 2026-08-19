<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DonationResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_donation' => new DonationResource(
                $this->whenLoaded('requestDonation')
            ),
            'responder_client_id' => $this->responder_client_id,
            'responder' => $this->whenLoaded(
                'responder',
                fn () => new UserResource($this->responder->user)
            ),
            'responder_profile' => $this->whenLoaded(
                'responder',
                fn () => new ClientResource($this->responder)
            ),
            'additional_note' => $this->additional_note,
            'location' => $this->location,
            'status' => $this->status,
            'accepted_at' => $this->accepted_at,
            'rejected_at' => $this->rejected_at,
            'created_at' => $this->created_at,
        ];
    }
}
