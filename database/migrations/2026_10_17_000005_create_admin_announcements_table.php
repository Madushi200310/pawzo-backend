<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();

            $table->foreignId('admin_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('title', 150);
            $table->text('body');
            $table->string('audience', 20);
            $table->json('user_ids')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_announcements');
    }
};