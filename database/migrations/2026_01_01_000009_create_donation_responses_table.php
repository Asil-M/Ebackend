<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_donation_id')
                ->constrained('donations')
                ->cascadeOnDelete();
            $table->foreignId('responder_client_id')
                ->constrained('clients')
                ->cascadeOnDelete();
            $table->text('additional_note')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->unique([
                'request_donation_id',
                'responder_client_id',
            ], 'donation_responses_unique_helper');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_responses');
    }
};
