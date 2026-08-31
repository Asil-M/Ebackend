<?php

namespace App\Models;

use App\Enums\DonationCategory;
use App\Enums\DonationLocation;
use App\Enums\DonationStatus;
use App\Enums\DonationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Donation extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => DonationType::class,
            'category' => DonationCategory::class,
            'location' => DonationLocation::class,
            'status' => DonationStatus::class,
            'details' => 'array',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function requestMatches()
    {
        return $this->hasMany(MatchedDonation::class, 'request_donation_id');
    }

    public function offeredMatches()
    {
        return $this->hasMany(MatchedDonation::class, 'offered_donation_id');
    }

    public function responses()
    {
        return $this->hasMany(DonationResponse::class, 'request_donation_id');
    }
}
