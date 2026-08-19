<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('matched_donations', 'matched_quantity')) {
            return;
        }

        Schema::table('matched_donations', function (Blueprint $table) {
            // Historical matches predate quantity tracking, so this remains
            // nullable for those rows. Every newly created match sets a value.
            $table->decimal('matched_quantity', 12, 2)
                ->nullable()
                ->after('offered_donation_id');
        });
    }

    public function down(): void
    {
        Schema::table('matched_donations', function (Blueprint $table) {
            $table->dropColumn('matched_quantity');
        });
    }
};
