<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'admins',
        'dispatchers',
        'drivers',
        'residents',
        'emergency_reports',
        'vehicles',
        'response_teams',
        'incident_types',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->softDeletes();
                $table->timestamp('purge_scheduled_at')->nullable()->index();
                $table->string('archived_by_type')->nullable();
                $table->unsignedBigInteger('archived_by_id')->nullable();
                $table->string('archived_by_name')->nullable();
                $table->string('purge_requested_by_type')->nullable();
                $table->unsignedBigInteger('purge_requested_by_id')->nullable();
                $table->string('purge_requested_by_name')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['purge_scheduled_at']);
                $table->dropSoftDeletes();
                $table->dropColumn([
                    'purge_scheduled_at',
                    'archived_by_type',
                    'archived_by_id',
                    'archived_by_name',
                    'purge_requested_by_type',
                    'purge_requested_by_id',
                    'purge_requested_by_name',
                ]);
            });
        }
    }
};
