<?php

/**
 * Add this entry inside the 'disks' array of your app's config/filesystems.php.
 *
 * For large files, point this at S3 (or any S3-compatible store) instead of 'local' -
 * local disk is fine for typical PDFs/images/Office docs, but for very large files
 * (multi-GB video, print-ready artwork) S3 + multipart/chunked upload on the frontend
 * is the more reliable path than a single PHP-handled form upload.
 */
return [

    'documents' => [
        'driver' => 'local',
        'root' => storage_path('app/documents'),
        'throw' => true,
    ],

    // Example S3 alternative:
    // 'documents' => [
    //     'driver' => 's3',
    //     'key' => env('DOCS_AWS_ACCESS_KEY_ID'),
    //     'secret' => env('DOCS_AWS_SECRET_ACCESS_KEY'),
    //     'region' => env('DOCS_AWS_DEFAULT_REGION'),
    //     'bucket' => env('DOCS_AWS_BUCKET'),
    //     'throw' => true,
    // ],

];
