<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sos_requests', function (Blueprint $table) {
            $table->foreignId('rejected_by_sos_team_id')
                ->nullable()
                ->after('accepted_by_sos_team_id')
                ->constrained('sos_teams')
                ->nullOnDelete();
            $table->string('rejection_reason')->nullable()->after('failed_at');
            $table->timestamp('rejected_at')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('sos_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by_sos_team_id');
            $table->dropColumn(['rejection_reason', 'rejected_at']);
        });
    }
};
