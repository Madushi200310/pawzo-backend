<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'vaccination',
                'medication',
                'deworming',
                'vet_appointment',
                'grooming',
            ]);
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date');
            $table->timestamp('remind_at')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_dismissed')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'due_date']);
            $table->index(['pet_id', 'due_date']);
            $table->index(['type', 'due_date']);
            $table->unique(['source_type', 'source_id', 'type'], 'reminders_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};