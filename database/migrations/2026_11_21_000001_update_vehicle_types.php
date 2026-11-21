<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_vehicle_type_check');
        }

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('vehicle_type', 40)->default('ambulance')->change();
        });

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $table->dropForeign(['reviewed_by']);
        });

        DB::table('vehicles')
            ->whereIn('vehicle_type', ['fire-truck', 'rescue-van'])
            ->update(['vehicle_type' => 'rescue-truck']);

        DB::table('vehicles')
            ->whereIn('vehicle_type', ['utility-truck', 'police-car'])
            ->update(['vehicle_type' => 'tow-truck']);
    }

    public function down(): void
    {
        // Keep string column for compatibility.
    }
};
