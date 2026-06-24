<?php
return [
    'displayErrorDetails' => true,   // set false in production
    'db' => [
        'host'    => 'localhost',
        'name'    => 'balonkydecor',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'languages'       => ['cs', 'ru', 'en', 'uk', 'sk'],
    'default_lang'    => 'cs',
    'upload_dir'      => __DIR__ . '/../www/assets/uploads/',
    'upload_url'      => '/assets/uploads/',
    'thumb_width'     => 400,
    'image_max_width' => 1600,
];
