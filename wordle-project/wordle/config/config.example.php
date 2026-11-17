<?php
// Copy this file to config/config.php and edit it, OR set the environment variables below.
// This file is never served to browsers (see config/.htaccess).
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'wordle',
        'user' => getenv('DB_USER') ?: 'wordle_user',
        'pass' => getenv('DB_PASS') ?: 'change_me',
    ],
    // Largest game duration (seconds) the server accepts.
    'max_duration_seconds' => (int)(getenv('MAX_DURATION_SECONDS') ?: 7200),
];
