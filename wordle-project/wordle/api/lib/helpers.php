<?php
declare(strict_types=1);

/** Send a JSON response and stop. */
function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Read and decode a JSON request body. Requires Content-Type: application/json (also blocks cross-site form posts). */
function json_body(): array
{
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        respond(415, ['error' => 'Content-Type must be application/json.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 10001);
    if ($raw === false || strlen($raw) > 10000) respond(413, ['error' => 'Request body too large.']);
    $data = json_decode($raw, true);
    if (!is_array($data)) respond(400, ['error' => 'Request body must be a JSON object.']);
    return $data;
}

/** Return a string field from a decoded body, or '' when missing or not a string. */
function str_field(array $body, string $key): string
{
    return isset($body[$key]) && is_string($body[$key]) ? $body[$key] : '';
}

/** Start the session with a hardened cookie. */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    session_name('WORDLESESSID');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

/** Return the logged-in user id or end the request with 401. */
function require_auth(): int
{
    start_session();
    if (empty($_SESSION['user_id'])) respond(401, ['error' => 'Authentication required.']);
    return (int)$_SESSION['user_id'];
}

/** Aggregate statistics for one player, computed from the games table (single source of truth). */
function user_stats(PDO $pdo, int $userId): array
{
    $sql = "SELECT
              COUNT(*) AS games_played,
              COALESCE(SUM(CASE WHEN status IN ('won','lost') THEN 1 ELSE 0 END), 0) AS completed_games,
              COALESCE(SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END), 0) AS games_won,
              COALESCE(SUM(CASE WHEN status IN ('won','lost') THEN score ELSE 0 END), 0) AS cumulative_score,
              COALESCE(SUM(CASE WHEN status IN ('won','lost') THEN duration_seconds ELSE 0 END), 0) AS total_time_seconds
            FROM games WHERE user_id = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$userId]);
    return array_map('intval', $st->fetch());
}
