<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();
            $table->string('path');                 // relative path on 'public' disk
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['pet_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_photos');
    }
};