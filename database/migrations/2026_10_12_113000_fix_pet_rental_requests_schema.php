<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_rental_requests', function (Blueprint $table) {
            $table->renameColumn('user_id', 'requester_id');
        });

        Schema::table('pet_rental_requests', function (Blueprint $table) {
            $table->string('purpose', 200)->nullable()->after('message');
            $table->text('owner_response')->nullable()->after('purpose');
            $table->timestampTz('responded_at')->nullable()->after('owner_response');
        });
    }

    public function down(): void
    {
        Schema::table('pet_rental_requests', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'owner_response', 'responded_at']);
            $table->renameColumn('requester_id', 'user_id');
        });
    }
};
