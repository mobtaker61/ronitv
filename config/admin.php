<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Management Area Credentials
    |--------------------------------------------------------------------------
    |
    | Protects /upload, /create-folder and /download-url with HTTP Basic auth.
    | Generate the hash with:  php artisan tinker --execute="echo Hash::make('your-password');"
    | Wrap the hash in single quotes in .env because it contains "$" characters.
    |
    */

    'user' => env('ADMIN_USER'),

    'password_hash' => env('ADMIN_PASSWORD_HASH'),

    /*
    |--------------------------------------------------------------------------
    | Upload / Download Limits
    |--------------------------------------------------------------------------
    |
    | Sizes are in kilobytes. They must stay at or below PHP's upload_max_filesize /
    | post_max_size and nginx's client_max_body_size on the server.
    |
    */

    'max_upload_kb' => (int) env('MAX_UPLOAD_KB', 262144),

    'max_download_kb' => (int) env('MAX_DOWNLOAD_KB', 2097152),

];
