<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Serializes matching refresh operations on PostgreSQL.
        Schema::create('pet_matching_locks', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });

        DB::table('pet_matching_locks')->insert([
            'id' => 1,
        ]);

        Schema::create('pet_matches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lost_pet_report_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('found_pet_report_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('score', 5, 2);
            $table->json('details');

            // Report timestamps used when calculating the match.
            $table->timestampTz('lost_updated_at');
            $table->timestampTz('found_updated_at');

            $table->timestampsTz();

            $table->unique(
                ['lost_pet_report_id', 'found_pet_report_id'],
                'pet_matches_pair_unique'
            );

            $table->index(['lost_pet_report_id', 'score']);
            $table->index(['found_pet_report_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_matches');
        Schema::dropIfExists('pet_matching_locks');
    }
};