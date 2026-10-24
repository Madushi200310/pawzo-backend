<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make whatsapp_number nullable on both Lost and Found pet reports.
     *
     * Pawzo design marks whatsapp_number as optional (page 2 of the project
     * spec). The request validation was already updated to allow NULL, but
     * the database schema still enforced NOT NULL, causing HTTP 500 errors
     * when the field was omitted. This migration brings the schema in line
     * with the validation rules.
     */
    public function up(): void
    {
        Schema::table('lost_pet_reports', function (Blueprint $table) {
            $table->string('whatsapp_number', 20)->nullable()->change();
        });

        Schema::table('found_pet_reports', function (Blueprint $table) {
            $table->string('whatsapp_number', 20)->nullable()->change();
        });
    }

    /**
     * Revert whatsapp_number back to NOT NULL.
     *
     * WARNING: This will fail if any rows currently hold NULL in either
     * column. Roll back only after backfilling or deleting such rows.
     */
    public function down(): void
    {
        Schema::table('lost_pet_reports', function (Blueprint $table) {
            $table->string('whatsapp_number', 20)->nullable(false)->change();
        });

        Schema::table('found_pet_reports', function (Blueprint $table) {
            $table->string('whatsapp_number', 20)->nullable(false)->change();
        });
    }
};