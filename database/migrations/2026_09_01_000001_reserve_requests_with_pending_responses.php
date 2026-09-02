<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('donations')
            ->where('type', 'request')
            ->where('status', 'pending')
            ->whereExists(function ($query) {
                $query->selectRaw(1)
                    ->from('donation_responses')
                    ->whereColumn(
                        'donation_responses.request_donation_id',
                        'donations.id'
                    )
                    ->where('donation_responses.status', 'pending');
            })
            ->update(['status' => 'awaiting_review']);
    }

    public function down(): void
    {
        DB::table('donations')
            ->where('status', 'awaiting_review')
            ->update(['status' => 'pending']);
    }
};
