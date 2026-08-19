<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sosRequests()
    {
        return $this->hasMany(SosRequest::class);
    }

    public function donations()
    {
        return $this->hasMany(Donation::class);
    }

    public function donationResponses()
    {
        return $this->hasMany(DonationResponse::class, 'responder_client_id');
    }
}
