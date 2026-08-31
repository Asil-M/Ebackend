<?php

namespace App\Models;

use App\Enums\MatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MatchedDonation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => MatchStatus::class,
            'matched_quantity' => 'decimal:2',
            'matched_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function team()
    {
        return $this->belongsTo(DonationTeam::class, 'donation_team_id');
    }

    public function requestDonation()
    {
        return $this->belongsTo(Donation::class, 'request_donation_id')
            ->withTrashed();
    }

    public function offeredDonation()
    {
        return $this->belongsTo(Donation::class, 'offered_donation_id')
            ->withTrashed();
    }
}
