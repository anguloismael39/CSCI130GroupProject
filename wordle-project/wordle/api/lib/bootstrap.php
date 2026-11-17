<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/handlers.php';

// Any uncaught error (for example the database being down) becomes a safe JSON 500.
set_exception_handler(function (Throwable $e): void {
    error_log('Wordle API error: ' . $e->getMessage());
    if (!headers_sent()) respond(500, ['error' => 'Server error. Please try again later.']);
});
