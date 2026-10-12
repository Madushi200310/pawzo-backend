<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lost pet reports
        Schema::table('lost_pet_reports', function (Blueprint $table) {
            $table->timestampTz('flagged_at')->nullable()->after('resolved_at');
            $table->text('flag_reason')->nullable()->after('flagged_at');
            $table->index(['flagged_at', 'status']);
        });

        // Found pet reports
        Schema::table('found_pet_reports', function (Blueprint $table) {
            $table->timestampTz('flagged_at')->nullable()->after('resolved_at');
            $table->text('flag_reason')->nullable()->after('flagged_at');
            $table->index(['flagged_at', 'status']);
        });

        // Pet matches
        Schema::table('pet_matches', function (Blueprint $table) {
            $table->timestampTz('flagged_at')->nullable()->after('score');
            $table->text('flag_reason')->nullable()->after('flagged_at');
            $table->index('flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('lost_pet_reports', function (Blueprint $table) {
            $table->dropIndex(['flagged_at', 'status']);
            $table->dropColumn(['flagged_at', 'flag_reason']);
        });

        Schema::table('found_pet_reports', function (Blueprint $table) {
            $table->dropIndex(['flagged_at', 'status']);
            $table->dropColumn(['flagged_at', 'flag_reason']);
        });

        Schema::table('pet_matches', function (Blueprint $table) {
            $table->dropIndex(['flagged_at']);
            $table->dropColumn(['flagged_at', 'flag_reason']);
        });
    }
};