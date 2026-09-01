<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            // 'report' => true doesn't change behavior (still returns false, callers still
            // check for it) — it just makes Laravel call the exception handler's report() on
            // the real Flysystem failure (permission denied, disk full, etc.) instead of
            // discarding it silently, so a "File could not be saved" a user hits actually
            // leaves a diagnosable entry in storage/logs/laravel.log (2026-09-01, previously
            // this reason was unrecoverable — see claude.md).
            'report' => true,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            // 'report' => true doesn't change behavior (still returns false, callers still
            // check for it) — it just makes Laravel call the exception handler's report() on
            // the real Flysystem failure (permission denied, disk full, etc.) instead of
            // discarding it silently, so a "File could not be saved" a user hits actually
            // leaves a diagnosable entry in storage/logs/laravel.log (2026-09-01, previously
            // this reason was unrecoverable — see claude.md).
            'report' => true,
            // Flysystem's default dir permission (0755) locks out the queue worker
            // (runs as a different user than the web server) from writing into any
            // freshly auto-created folder. Group-writable so both can write.
            'permissions' => [
                'file' => ['public' => 0664, 'private' => 0600],
                'dir' => ['public' => 0775, 'private' => 0700],
            ],
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            // 'report' => true doesn't change behavior (still returns false, callers still
            // check for it) — it just makes Laravel call the exception handler's report() on
            // the real Flysystem failure (permission denied, disk full, etc.) instead of
            // discarding it silently, so a "File could not be saved" a user hits actually
            // leaves a diagnosable entry in storage/logs/laravel.log (2026-09-01, previously
            // this reason was unrecoverable — see claude.md).
            'report' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
