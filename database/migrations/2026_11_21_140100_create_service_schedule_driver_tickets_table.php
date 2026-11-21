<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_schedule_driver_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_schedule_id')
                ->unique()
                ->constrained('service_schedules')
                ->cascadeOnDelete();
            $table->foreignId('driver_id')
                ->nullable()
                ->constrained('drivers')
                ->nullOnDelete();
            $table->json('driver_section');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_schedule_driver_tickets');
    }
};
