<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();

            // Preserve the historical ID even if the admin is deleted.
            $table->unsignedBigInteger('admin_id');
            $table->string('method', 10);
            $table->string('route', 500);
            $table->string('action', 500);
            $table->json('targets')->nullable();
            $table->unsignedSmallInteger('response_status');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['admin_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};