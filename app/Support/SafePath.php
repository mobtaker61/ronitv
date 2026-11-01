<?php

namespace App\Support;

use InvalidArgumentException;

class SafePath
{
    /**
     * Normalise a user-supplied relative path for the videos disk.
     * Rejects traversal, absolute paths, backslashes and control characters.
     */
    public static function clean(?string $path): string
    {
        $path = trim((string) $path, '/');

        if ($path === '') {
            return '';
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path)) {
            throw new InvalidArgumentException('Invalid path.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Invalid path.');
            }
        }

        return $path;
    }

    /**
     * Same as clean(), but for a single file or folder name (no slashes).
     */
    public static function name(string $name): string
    {
        $name = trim($name);

        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new InvalidArgumentException('Invalid name.');
        }

        return self::clean($name);
    }
}
