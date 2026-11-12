<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_calls', function (Blueprint $table): void {
            $table->string('guest_token_hash', 64)->nullable();
            $table->string('guest_name', 120)->nullable();
            $table->string('guest_contact', 40)->nullable();
            $table->string('guest_address', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('communication_calls', function (Blueprint $table): void {
            $table->dropColumn(['guest_token_hash', 'guest_name', 'guest_contact', 'guest_address']);
        });
    }
};
