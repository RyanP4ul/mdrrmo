<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('announcements');
    }

    public function down(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->enum('category', ['Weather Advisory', 'Evacuation', 'Traffic Advisory', 'Information', 'Relief Operations']);
            $table->text('description');
            $table->string('image_path')->nullable();
            $table->unsignedBigInteger('posted_by');
            $table->string('posted_by_type')->nullable();
            $table->index(['posted_by_type', 'posted_by']);
            $table->timestamps();
        });
    }
};
