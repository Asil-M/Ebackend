<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('category');
            $table->text('additional_note')->nullable();
            $table->string('location');
            $table->json('details');
            $table->string('status')->default('pending');
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
