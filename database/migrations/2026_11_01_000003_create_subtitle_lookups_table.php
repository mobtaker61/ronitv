<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remembers what the online subtitle search decided for each video, so the API
     * is asked once: a found subtitle is stored next to the video, a miss is retried only after a while.
     */
    public function up(): void
    {
        Schema::create('subtitle_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('video_path', 500)->unique();
            $table->string('lang', 8)->default('fa');
            $table->string('status', 16); // found | none
            $table->unsignedBigInteger('subtitle_id')->nullable();
            $table->unsignedBigInteger('movie_id')->nullable();
            $table->string('release')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_lookups');
    }
};
