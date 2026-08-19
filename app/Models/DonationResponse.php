<?php

namespace App\Models;

use App\Enums\DonationResponseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DonationResponse extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'location' => \App\Enums\DonationLocation::class,
            'status' => DonationResponseStatus::class,
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function requestDonation()
    {
        return $this->belongsTo(Donation::class, 'request_donation_id');
    }

    public function responder()
    {
        return $this->belongsTo(Client::class, 'responder_client_id');
    }
}
