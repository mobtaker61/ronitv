<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubtitleLookup extends Model
{
    public const FOUND = 'found';

    public const NONE = 'none';

    protected $fillable = ['video_path', 'lang', 'status', 'subtitle_id', 'movie_id', 'release', 'checked_at'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }
}
