<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_status_check');
        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_vehicle_type_check');
        DB::statement('ALTER TABLE service_schedules DROP CONSTRAINT IF EXISTS service_schedules_status_check');
    }

    public function down(): void
    {
        // Keep string columns without legacy enum CHECK constraints so newer values remain valid.
    }
};
