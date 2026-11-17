<?php
declare(strict_types=1);

const SCORE_BASE = 1024;
const MAX_GUESSES = 6;

/** GET /api/words */
function handle_words(): void
{
    $rows = db()->query('SELECT word, is_answer FROM words ORDER BY word')->fetchAll();
    if (!$rows) {
        respond(503, ['error' => 'The word list has not been loaded on the server.']);
    }
    $answers = [];
    foreach ($rows as $r) {
        if ((int)$r['is_answer'] === 1) $answers[] = $r['word'];
    }
    respond(200, ['answers' => $answers, 'allowed' => array_column($rows, 'word')]);
}

/** POST /api/auth/register */
function handle_register(): void
{
    $body = json_body();
    $username = trim(str_field($body, 'username'));
    $password = str_field($body, 'password');

    $errors = [];
    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
        $errors['username'] = 'Username must be 3-20 characters: letters, digits or underscore.';
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        $errors['password'] = 'Password must be 8-72 characters long.';
    }
    if ($errors) respond(400, ['error' => 'Please correct the form and try again.', 'errors' => $errors]);

    $pdo = db();
    try {
        $st = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
        $st->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { // unique constraint on username
            respond(409, ['error' => 'That username is already taken.', 'errors' => ['username' => 'That username is already taken.']]);
        }
        throw $e;
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$pdo->lastInsertId();
    respond(201, ['user' => ['username' => $username]]);
}

/** POST /api/auth/login */
function handle_login(): void
{
    $body = json_body();
    $username = trim(str_field($body, 'username'));
    $password = str_field($body, 'password');

    $st = db()->prepare('SELECT user_id, username, password_hash FROM users WHERE username = ?');
    $st->execute([$username]);
    $user = $st->fetch();

    // Always run a hash comparison so the response time does not reveal whether the username exists.
    $hash = $user['password_hash'] ?? password_hash('placeholder', PASSWORD_DEFAULT);
    if (!password_verify($password, $hash) || !$user) {
        respond(401, ['error' => 'Invalid username or password.']);
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['user_id'];
    respond(200, ['user' => ['username' => $user['username']]]);
}

/** POST /api/auth/logout */
function handle_logout(): void
{
    require_auth();
    json_body();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
        'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
    respond(200, ['message' => 'Logged out.']);
}

/** GET /api/me */
function handle_me(): void
{
    $uid = require_auth();
    $pdo = db();
    $st = $pdo->prepare('SELECT username FROM users WHERE user_id = ?');
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row) respond(401, ['error' => 'Authentication required.']);
    respond(200, ['user' => ['username' => $row['username']], 'stats' => user_stats($pdo, $uid)]);
}

/** POST /api/games/start - abandons any unfinished game, then creates a new one. */
function handle_game_start(): void
{
    $uid = require_auth();
    json_body();
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE games SET status = 'abandoned' WHERE user_id = ? AND status = 'in_progress'");
    $st->execute([$uid]);
    $st = $pdo->prepare("INSERT INTO games (user_id, status) VALUES (?, 'in_progress')");
    $st->execute([$uid]);
    $gameId = (int)$pdo->lastInsertId();
    $pdo->commit();
    respond(201, ['game_id' => $gameId]);
}

/** POST /api/games/{id}/complete - records the terminal result exactly once; the score is computed here. */
function handle_game_complete(array $params): void
{
    $uid = require_auth();
    $gameId = (int)$params[0];
    $body = json_body();
    $pdo = db();

    $st = $pdo->prepare('SELECT user_id, status FROM games WHERE game_id = ?');
    $st->execute([$gameId]);
    $game = $st->fetch();
    if (!$game) respond(404, ['error' => 'Game not found.']);
    if ((int)$game['user_id'] !== $uid) respond(403, ['error' => 'This game belongs to another player.']);
    if ($game['status'] !== 'in_progress') respond(409, ['error' => 'This game has already been finished.']);

    $won = $body['won'] ?? null;
    $attempts = $body['attempts'] ?? null;
    $duration = $body['duration_seconds'] ?? null;
    $max = (int)config()['max_duration_seconds'];

    $errors = [];
    if (!is_bool($won)) $errors[] = 'won must be true or false.';
    if (!is_int($attempts) || $attempts < 1 || $attempts > MAX_GUESSES) $errors[] = 'attempts must be an integer from 1 to 6.';
    elseif ($won === false && $attempts !== MAX_GUESSES) $errors[] = 'A lost game must use all 6 attempts.';
    if ((!is_int($duration) && !is_float($duration)) || !is_finite((float)$duration) || $duration < 0 || $duration > $max) {
        $errors[] = "duration_seconds must be a number from 0 to $max.";
    }
    if ($errors) respond(400, ['error' => implode(' ', $errors)]);

    $score = $won ? intdiv(SCORE_BASE, 2 ** ($attempts - 1)) : 0;
    $status = $won ? 'won' : 'lost';
    $seconds = (int)round((float)$duration);

    // The status condition makes the update atomic, so a second (or concurrent) completion changes nothing.
    $st = $pdo->prepare("UPDATE games SET status = ?, completed_at = NOW(), attempts_used = ?, score = ?, duration_seconds = ?
                         WHERE game_id = ? AND user_id = ? AND status = 'in_progress'");
    $st->execute([$status, $attempts, $score, $seconds, $gameId, $uid]);
    if ($st->rowCount() !== 1) respond(409, ['error' => 'This game has already been finished.']);

    respond(200, [
        'game' => ['game_id' => $gameId, 'status' => $status, 'attempts' => $attempts, 'score' => $score, 'duration_seconds' => $seconds],
        'stats' => user_stats($pdo, $uid),
    ]);
}

/** GET /api/leaderboard?sort=score&dir=desc */
function handle_leaderboard(): void
{
    // Whitelist: user input only ever selects one of these fixed SQL fragments.
    $columns = [
        'username'        => 'u.username',
        'score'           => 'score',
        'games_played'    => 'games_played',
        'total_time'      => 'total_time_seconds',
        'completed_games' => 'completed_games',
    ];
    $sort = $_GET['sort'] ?? 'score';
    $dir = $_GET['dir'] ?? 'desc';
    if (!is_string($sort) || !isset($columns[$sort])) respond(400, ['error' => 'Invalid sort field.']);
    if (!is_string($dir) || !in_array($dir, ['asc', 'desc'], true)) respond(400, ['error' => 'Invalid sort direction.']);
    $direction = $dir === 'asc' ? 'ASC' : 'DESC';

    $sql = "SELECT u.username,
                   COALESCE(SUM(CASE WHEN g.status IN ('won','lost') THEN g.score ELSE 0 END), 0) AS score,
                   COUNT(g.game_id) AS games_played,
                   COALESCE(SUM(CASE WHEN g.status IN ('won','lost') THEN g.duration_seconds ELSE 0 END), 0) AS total_time_seconds,
                   COALESCE(SUM(CASE WHEN g.status IN ('won','lost') THEN 1 ELSE 0 END), 0) AS completed_games
            FROM users u
            LEFT JOIN games g ON g.user_id = u.user_id
            GROUP BY u.user_id, u.username
            ORDER BY {$columns[$sort]} $direction, completed_games DESC, total_time_seconds ASC, u.username ASC";
    $players = [];
    foreach (db()->query($sql)->fetchAll() as $r) {
        $players[] = [
            'username' => $r['username'],
            'score' => (int)$r['score'],
            'games_played' => (int)$r['games_played'],
            'total_time_seconds' => (int)$r['total_time_seconds'],
            'completed_games' => (int)$r['completed_games'],
        ];
    }
    respond(200, ['sort' => $sort, 'dir' => $dir, 'players' => $players]);
}
