<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_queue_lock', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('communication_queue_lock')->insert(['id' => 1]);
        Schema::create('communication_dispatchers', function (Blueprint $table): void {
            $table->string('account_key', 80)->primary();
            $table->boolean('accepting')->default(true);
            $table->timestamp('last_seen_at');
        });
        Schema::table('communication_calls', function (Blueprint $table): void {
            $table->string('offered_dispatcher_key', 80)->nullable()->index();
            $table->string('last_offered_dispatcher_key', 80)->nullable();
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('caller_seen_at')->nullable();
            $table->timestamp('dispatcher_seen_at')->nullable();
            $table->string('queue_state', 20)->default('waiting');
            $table->unsignedTinyInteger('priority')->default(2)->index();
            $table->string('end_reason', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('communication_calls', function (Blueprint $table): void {
            $table->dropIndex(['offered_dispatcher_key']);
            $table->dropIndex(['priority']);
            $table->dropColumn(['offered_dispatcher_key', 'last_offered_dispatcher_key', 'offered_at', 'caller_seen_at', 'dispatcher_seen_at', 'queue_state', 'priority', 'end_reason']);
        });
        Schema::dropIfExists('communication_dispatchers');
        Schema::dropIfExists('communication_queue_lock');
    }
};
