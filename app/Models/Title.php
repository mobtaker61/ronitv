<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Title extends Model
{
    public const AUTO = 'auto';

    public const MANUAL = 'manual';

    protected $fillable = [
        'folder', 'tmdb_id', 'media_type', 'matched_by', 'title', 'original_title', 'year',
        'poster_path', 'imdb_id', 'details', 'fetched_at',
    ];

    protected function casts(): array
    {
        return ['details' => 'array', 'fetched_at' => 'datetime', 'tmdb_id' => 'integer', 'year' => 'integer'];
    }

    public function isMatched(): bool
    {
        return $this->tmdb_id !== null;
    }

    public function isManual(): bool
    {
        return $this->matched_by === self::MANUAL;
    }
}
