<?php

namespace App\Models;

use App\Enums\SosStatus;
use App\Enums\SosType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SosRequest extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => SosType::class,
            'status' => SosStatus::class,
            'accepted_at' => 'datetime',
            'failed_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'service_latitude' => 'decimal:7',
            'service_longitude' => 'decimal:7',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function acceptedBy()
    {
        return $this->belongsTo(SosTeam::class, 'accepted_by_sos_team_id');
    }
}
