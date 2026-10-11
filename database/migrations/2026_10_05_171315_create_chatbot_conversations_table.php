<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_conversations', function (Blueprint $table) {
            $table->id();

            // Owner (nullable for guests)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Session ID for guests
            $table->string('session_id')->nullable()->index();

            // Metadata
            $table->string('title')->nullable();            // auto-generated from first message
            $table->boolean('is_active')->default(true);
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['session_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_conversations');
    }
};