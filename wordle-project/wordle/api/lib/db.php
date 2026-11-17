<?php
declare(strict_types=1);

/** Load configuration (config/config.php if present, otherwise config.example.php + environment variables). */
function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $dir = dirname(__DIR__, 2) . '/config/';
        $cfg = require $dir . (is_file($dir . 'config.php') ? 'config.php' : 'config.example.php');
    }
    return $cfg;
}

/** Shared PDO connection. Exceptions are thrown on errors; prepared statements are used everywhere. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config()['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
