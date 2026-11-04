<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 64);
            $table->uuid('submission_key');
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('encrypted_response')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'submission_key']);
        });
        Schema::create('resident_consents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('resident_id');
            $table->foreign('resident_id')->references('id')->on('residents')->cascadeOnDelete();
            $table->string('version', 30);
            $table->text('statement');
            $table->timestamp('consented_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_consents');
        Schema::dropIfExists('mobile_submissions');
    }
};
