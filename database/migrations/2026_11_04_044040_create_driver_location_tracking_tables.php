<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_trips', function (Blueprint $table): void {
            $table->timestampTz('returning_at')->nullable();
        });
        Schema::create('driver_location_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->boolean('background')->default(false);
            $table->string('notice_version', 30);
            $table->timestampTz('started_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('stopped_at')->nullable();
            $table->timestampTz('last_observed_at')->nullable();
            $table->unsignedBigInteger('last_sequence')->default(0);
        });
        Schema::create('driver_locations', function (Blueprint $table): void {
            $table->foreignId('driver_id')->primary()->constrained('drivers')->cascadeOnDelete();
            $table->uuid('session_id');
            $table->double('lat');
            $table->double('lng');
            $table->double('accuracy');
            $table->unsignedSmallInteger('speed')->default(0);
            $table->unsignedSmallInteger('heading')->default(0);
            $table->timestampTz('observed_at');
            $table->timestampTz('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_locations');
        Schema::dropIfExists('driver_location_sessions');
        Schema::table('dispatch_trips', function (Blueprint $table): void {
            $table->dropColumn('returning_at');
        });
    }
};
