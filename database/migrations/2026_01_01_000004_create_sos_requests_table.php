<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sos_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accepted_by_sos_team_id')
                ->nullable()
                ->constrained('sos_teams')
                ->nullOnDelete();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->string('location_name');
            $table->text('description');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('service_name')->nullable();
            $table->decimal('service_latitude', 10, 7)->nullable();
            $table->decimal('service_longitude', 10, 7)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sos_requests');
    }
};
