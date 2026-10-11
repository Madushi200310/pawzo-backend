<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaccinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();
            $table->string('vaccine_name');
            $table->date('given_date');
            $table->date('next_due_date')->nullable();
            $table->string('vet_name')->nullable();
            $table->string('batch_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['pet_id', 'given_date']);
            $table->index(['pet_id', 'next_due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccinations');
    }
};