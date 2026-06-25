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
    'migrate_token'   => '8b1b4af4ff83a007dda3fc43ab1c7f43372884714905281f9d1e61cc46c3a781',
];
