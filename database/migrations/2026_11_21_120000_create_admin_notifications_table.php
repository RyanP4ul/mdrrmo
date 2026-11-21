<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 30);
            $table->unsignedBigInteger('subject_id');
            $table->string('title');
            $table->timestamp('created_at');
            $table->unique(['kind', 'subject_id']);
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
