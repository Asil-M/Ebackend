<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_responses', function (Blueprint $table) {
            $table->string('location')->nullable()->after('additional_note');
        });
    }

    public function down(): void
    {
        Schema::table('donation_responses', function (Blueprint $table) {
            $table->dropIndex(['location']);
            $table->dropColumn('location');
        });
    }
};
