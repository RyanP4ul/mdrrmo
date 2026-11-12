<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatchers', function (Blueprint $table): void {
            $table->string('phone_number', 28)->nullable();
        });
        DB::table('dispatchers')->whereNotNull('contact_number')->update(['phone_number' => DB::raw('contact_number')]);
    }

    public function down(): void
    {
        Schema::table('dispatchers', function (Blueprint $table): void {
            $table->dropColumn('phone_number');
        });
    }
};
