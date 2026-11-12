<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitals', function (Blueprint $table): void {
            $table->unsignedSmallInteger('available_beds')->nullable()->default(null)->change();
            $table->text('source_url')->nullable();
            $table->text('location_source_url')->nullable();
        });
    }

    public function down(): void
    {
        DB::table('hospitals')->whereNull('available_beds')->update(['available_beds' => 0]);
        Schema::table('hospitals', function (Blueprint $table): void {
            $table->dropColumn(['source_url', 'location_source_url']);
            $table->unsignedSmallInteger('available_beds')->nullable(false)->default(0)->change();
        });
    }
};
