<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matched_donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_team_id')
                ->nullable()
                ->constrained('donation_teams')
                ->nullOnDelete();
            $table->foreignId('request_donation_id')
                ->constrained('donations')
                ->restrictOnDelete();
            $table->foreignId('offered_donation_id')
                ->constrained('donations')
                ->restrictOnDelete();
            $table->string('status')->default('matched');
            $table->timestamp('matched_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['request_donation_id', 'offered_donation_id'],
                'matched_donations_unique_pair'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matched_donations');
    }
};
