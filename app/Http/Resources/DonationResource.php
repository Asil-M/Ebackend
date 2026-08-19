<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DonationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'owner' => $this->whenLoaded('client', function () {
                return [
                    'user' => new UserResource($this->client->user),
                    'profile' => new ClientResource($this->client),
                ];
            }),
            'type' => $this->type,
            'category' => $this->category,
            'additional_note' => $this->additional_note,
            'location' => $this->location,
            'details' => $this->details,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
