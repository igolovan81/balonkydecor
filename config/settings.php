<?php
return [
    'displayErrorDetails' => true,   // set false in production
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'balonkydecor',
        'user'    => 'balonky',
        'pass'    => 'balonky',
        'charset' => 'utf8mb4',
    ],
    'languages'       => ['cs', 'sk', 'en', 'uk', 'ru'],
    'default_lang'    => 'cs',
    'upload_dir'      => __DIR__ . '/../www/assets/uploads/',
    'upload_url'      => '/assets/uploads/',
    'thumb_width'     => 400,
    'image_max_width' => 1600,
];
