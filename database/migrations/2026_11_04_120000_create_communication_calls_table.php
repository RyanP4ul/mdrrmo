<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('emergency_report_id')->constrained()->cascadeOnDelete();
            $table->string('resident_key', 80)->index();
            $table->string('dispatcher_key', 80)->index();
            $table->string('responder_key', 80)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_calls');
    }
};
