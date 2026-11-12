<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_calls', function (Blueprint $table): void {
            $table->unsignedBigInteger('emergency_report_id')->nullable()->change();
            $table->string('dispatcher_key', 80)->nullable()->change();
            $table->string('responder_key', 80)->nullable()->change();
            $table->string('kind', 20)->default('report')->index();
            $table->timestamp('accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        DB::table('communication_calls')->where('kind', 'sos')->delete();
        Schema::table('communication_calls', function (Blueprint $table): void {
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'accepted_at']);
            $table->unsignedBigInteger('emergency_report_id')->nullable(false)->change();
            $table->string('dispatcher_key', 80)->nullable(false)->change();
            $table->string('responder_key', 80)->nullable(false)->change();
        });
    }
};
