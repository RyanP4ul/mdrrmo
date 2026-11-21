<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->string('service_details', 255)->nullable();
            $table->string('destination_address')->nullable();
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();
            $table->decimal('destination_lat', 10, 7)->nullable();
            $table->decimal('destination_lng', 10, 7)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->dropColumn(['service_details', 'destination_address', 'pickup_lat', 'pickup_lng', 'destination_lat', 'destination_lng']);
        });
    }
};
