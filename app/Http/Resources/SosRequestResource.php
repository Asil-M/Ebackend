<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SosRequestResource extends JsonResource
{
    private const AVERAGE_SPEED_KMH = 50;

    private const EARTH_RADIUS_KM = 6371;

    public function toArray(Request $request): array
    {
        $distance = $this->calculateDistance();
        $etaMinutes = $distance === null
            ? null
            : (int) ceil($distance / self::AVERAGE_SPEED_KMH * 60);

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'owner' => $this->whenLoaded('client', function () {
                return [
                    'user' => new UserResource($this->client->user),
                    'profile' => new ClientResource($this->client),
                ];
            }),
            'accepted_by_sos_team_id' => $this->accepted_by_sos_team_id,
            'type' => $this->type,
            'status' => $this->status,
            'location_name' => $this->location_name,
            'description' => $this->description,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'service_name' => $this->service_name,
            'service_latitude' => $this->service_latitude,
            'service_longitude' => $this->service_longitude,
            'distance_km' => $distance,
            'eta_minutes' => $etaMinutes,
            'accepted_at' => $this->accepted_at,
            'failed_at' => $this->failed_at,
            'created_at' => $this->created_at,
        ];
    }

    private function calculateDistance(): ?float
    {
        if ($this->service_latitude === null || $this->service_longitude === null) {
            return null;
        }

        $latitudeDifference = deg2rad(
            (float) $this->service_latitude - (float) $this->latitude
        );
        $longitudeDifference = deg2rad(
            (float) $this->service_longitude - (float) $this->longitude
        );

        $haversine = sin($latitudeDifference / 2) ** 2
            + cos(deg2rad((float) $this->latitude))
            * cos(deg2rad((float) $this->service_latitude))
            * sin($longitudeDifference / 2) ** 2;

        $distance = self::EARTH_RADIUS_KM
            * 2
            * atan2(sqrt($haversine), sqrt(1 - $haversine));

        return round($distance, 2);
    }
}
