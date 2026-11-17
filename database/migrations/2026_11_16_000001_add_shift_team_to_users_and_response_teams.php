<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatchers', function (Blueprint $table): void {
            $table->enum('shift_team', ['day', 'night'])->nullable()->after('status')->index();
        });

        Schema::table('drivers', function (Blueprint $table): void {
            $table->enum('shift_team', ['day', 'night'])->nullable()->after('status')->index();
        });

        Schema::table('response_teams', function (Blueprint $table): void {
            $table->enum('shift_team', ['day', 'night'])->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('response_teams', function (Blueprint $table): void {
            $table->dropIndex(['shift_team']);
            $table->dropColumn('shift_team');
        });

        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropIndex(['shift_team']);
            $table->dropColumn('shift_team');
        });

        Schema::table('dispatchers', function (Blueprint $table): void {
            $table->dropIndex(['shift_team']);
            $table->dropColumn('shift_team');
        });
    }
};
