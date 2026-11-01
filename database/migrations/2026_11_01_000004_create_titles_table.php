<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per top-level library folder: which TMDb movie/series the folder is, and what TMDb told us about it.
     * Fetched once and read from here afterwards, so pages and subtitle lookups never repeat the name search.
     */
    public function up(): void
    {
        Schema::create('titles', function (Blueprint $table) {
            $table->id();
            $table->string('folder')->unique();            // top-level folder name in the videos disk
            $table->unsignedBigInteger('tmdb_id')->nullable(); // null: searched, nothing found
            $table->string('media_type', 8)->nullable();   // movie | tv
            $table->string('matched_by', 8)->default('auto'); // auto (name guess) | manual (chosen by the admin)
            $table->string('title')->nullable();
            $table->string('original_title')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('poster_path')->nullable();
            $table->string('imdb_id', 16)->nullable();
            $table->json('details')->nullable();           // the full TMDb payload (credits, genres, ...)
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titles');
    }
};
