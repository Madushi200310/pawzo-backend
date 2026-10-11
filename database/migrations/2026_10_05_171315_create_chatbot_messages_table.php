<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')->constrained('chatbot_conversations')->cascadeOnDelete();

            // 'user' or 'assistant'
            $table->enum('role', ['user', 'assistant']);

            $table->text('message');

            // Which source generated it: 'rule', 'openai', 'fallback'
            $table->string('source')->nullable();

            // Optional metadata
            $table->integer('tokens_used')->nullable();
            $table->integer('response_time_ms')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_messages');
    }
};