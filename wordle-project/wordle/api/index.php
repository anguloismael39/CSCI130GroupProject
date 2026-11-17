<?php
declare(strict_types=1);
// Front controller: every /api/... request is routed here by api/.htaccess.
require __DIR__ . '/lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($base !== '' && strpos($path, $base) === 0) $path = substr($path, strlen($base));
$path = '/' . trim(preg_replace('#^/index\.php#', '', $path), '/');

$routes = [
    ['GET',  '#^/words$#',                    'handle_words'],
    ['POST', '#^/auth/register$#',            'handle_register'],
    ['POST', '#^/auth/login$#',               'handle_login'],
    ['POST', '#^/auth/logout$#',              'handle_logout'],
    ['GET',  '#^/me$#',                       'handle_me'],
    ['POST', '#^/games/start$#',              'handle_game_start'],
    ['POST', '#^/games/(\d+)/complete$#',     'handle_game_complete'],
    ['GET',  '#^/leaderboard$#',              'handle_leaderboard'],
];

$pathMatched = false;
foreach ($routes as [$routeMethod, $pattern, $handler]) {
    if (preg_match($pattern, $path, $m)) {
        $pathMatched = true;
        if ($method === $routeMethod) {
            $handler(array_slice($m, 1));
            exit;
        }
    }
}
respond($pathMatched ? 405 : 404, ['error' => $pathMatched ? 'Method not allowed.' : 'Not found.']);
