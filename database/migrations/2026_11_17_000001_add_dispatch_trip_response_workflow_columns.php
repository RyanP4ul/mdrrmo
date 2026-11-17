<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_trips', function (Blueprint $table): void {
            $table->timestampTz('driver_acknowledged_at')->nullable();
            $table->unsignedBigInteger('hospital_id')->nullable();
            $table->json('unavailable_hospital_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_trips', function (Blueprint $table): void {
            $table->dropColumn([
                'driver_acknowledged_at',
                'hospital_id',
                'unavailable_hospital_ids',
            ]);
        });
    }
};
