<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);              // smart_match_found, vaccination_reminder, etc.
            $table->string('title', 200);
            $table->text('body');
            $table->json('data')->nullable();        // Event-specific payload (match_id, booking_id, etc.)
            $table->string('action_url', 500)->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'read_at']);   // Unread count query
            $table->index(['user_id', 'created_at']); // List query
            $table->index('type');                    // Filter by type
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};