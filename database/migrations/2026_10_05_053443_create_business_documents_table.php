<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Document info
            $table->string('document_type');     // e.g., "business_registration"
            $table->string('file_path');         // stored path
            $table->string('original_name');     // original filename
            $table->string('mime_type');         // e.g., application/pdf, image/jpeg
            $table->unsignedBigInteger('file_size'); // bytes

            // Optional
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_documents');
    }
};