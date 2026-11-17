<?php
// Integration test of the HTTP API. Needs the site running and the database seeded.
// Usage: php tests/api_test.php http://localhost/wordle
declare(strict_types=1);
$base = rtrim($argv[1] ?? 'http://localhost/wordle', '/');
$pass = 0; $fail = 0;

function call(string $method, string $path, ?array $body, string $jar, string $contentType = 'application/json'): array
{
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType, 'Accept: application/json'],
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$raw, true)];
}
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
}

$jarA = tempnam(sys_get_temp_dir(), 'wa'); $jarB = tempnam(sys_get_temp_dir(), 'wb'); $jarG = tempnam(sys_get_temp_dir(), 'wg');
$userA = 'ta_' . substr(md5((string)microtime(true)), 0, 8);
$userB = 'tb_' . substr(md5((string)microtime(true) . 'b'), 0, 8);

[$c, $d] = call('GET', '/api/words', null, $jarG);
check('words: 200 with answers and allowed lists', $c === 200 && !empty($d['answers']) && count($d['allowed']) >= count($d['answers']));
[$c] = call('GET', '/api/me', null, $jarG);
check('me without login -> 401', $c === 401);
[$c] = call('POST', '/api/games/start', [], $jarG);
check('start game without login -> 401', $c === 401);
[$c] = call('POST', '/api/auth/logout', [], $jarG);
check('logout without login -> 401', $c === 401);

[$c] = call('POST', '/api/auth/register', ['username' => $userA, 'password' => 'secret123'], $jarA);
check('register valid -> 201', $c === 201);
[$c] = call('POST', '/api/auth/register', ['username' => $userA, 'password' => 'secret123'], $jarG);
check('register duplicate username -> 409', $c === 409);
[$c] = call('POST', '/api/auth/register', ['username' => 'x', 'password' => 'short'], $jarG);
check('register invalid input -> 400', $c === 400);
[$c] = call('POST', '/api/auth/register', ['username' => 'form_post'], $jarG, 'application/x-www-form-urlencoded');
check('non-JSON body -> 415', $c === 415);
[$c] = call('POST', '/api/auth/logout', [], $jarA);
check('logout -> 200', $c === 200);
[$c] = call('GET', '/api/me', null, $jarA);
check('me after logout -> 401', $c === 401);
[$c] = call('POST', '/api/auth/login', ['username' => $userA, 'password' => 'wrongpass1'], $jarA);
check('login wrong password -> 401', $c === 401);
[$c] = call('POST', '/api/auth/login', ['username' => $userA, 'password' => 'secret123'], $jarA);
check('login correct -> 200', $c === 200);

// Scores for wins on trials 1..6 and a loss
$expected = 0;
foreach ([[1, true, 1024], [2, true, 512], [3, true, 256], [4, true, 128], [5, true, 64], [6, true, 32], [6, false, 0]] as [$att, $won, $pts]) {
    [, $g] = call('POST', '/api/games/start', [], $jarA);
    [$c, $r] = call('POST', "/api/games/{$g['game_id']}/complete", ['won' => $won, 'attempts' => $att, 'duration_seconds' => 10], $jarA);
    check("complete trial $att " . ($won ? 'win' : 'loss') . " -> $pts points", $c === 200 && $r['game']['score'] === $pts);
    $expected += $pts;
}
[, $me] = call('GET', '/api/me', null, $jarA);
check('cumulative score is the sum of game scores', $me['stats']['cumulative_score'] === $expected);
check('games played / completed / won / time', $me['stats']['games_played'] === 7 && $me['stats']['completed_games'] === 7
    && $me['stats']['games_won'] === 6 && $me['stats']['total_time_seconds'] === 70);

// Duplicate completion, bad input, ownership, abandonment
[, $g] = call('POST', '/api/games/start', [], $jarA);
$gid = $g['game_id'];
[$c] = call('POST', "/api/games/$gid/complete", ['won' => true, 'attempts' => 2, 'duration_seconds' => -5], $jarA);
check('negative duration -> 400', $c === 400);
[$c] = call('POST', "/api/games/$gid/complete", ['won' => true, 'attempts' => 7, 'duration_seconds' => 5], $jarA);
check('attempts out of range -> 400', $c === 400);
[$c] = call('POST', "/api/games/$gid/complete", ['won' => true, 'attempts' => 2, 'duration_seconds' => 999999], $jarA);
check('duration above maximum -> 400', $c === 400);
call('POST', '/api/auth/register', ['username' => $userB, 'password' => 'secret123'], $jarB);
[$c] = call('POST', "/api/games/$gid/complete", ['won' => true, 'attempts' => 1, 'duration_seconds' => 1], $jarB);
check("another player's game -> 403", $c === 403);
[$c] = call('POST', "/api/games/$gid/complete", ['won' => true, 'attempts' => 2, 'duration_seconds' => 5], $jarA);
check('first completion -> 200', $c === 200);
[$c] = call('POST', "/api/games/$gid/complete", ['won' => true, 'attempts' => 2, 'duration_seconds' => 5], $jarA);
check('second completion -> 409', $c === 409);
[$c] = call('POST', '/api/games/999999999/complete', ['won' => true, 'attempts' => 2, 'duration_seconds' => 5], $jarA);
check('unknown game -> 404', $c === 404);
call('POST', '/api/games/start', [], $jarA);
call('POST', '/api/games/start', [], $jarA);   // abandons the previous one
[, $me2] = call('GET', '/api/me', null, $jarA);
check('abandoned games add no score or completed games', $me2['stats']['cumulative_score'] === $expected + 512
    && $me2['stats']['completed_games'] === 8 && $me2['stats']['games_played'] === 10);

// Leaderboard
$keys = ['username' => 'username', 'score' => 'score', 'games_played' => 'games_played',
         'total_time' => 'total_time_seconds', 'completed_games' => 'completed_games'];
foreach ($keys as $sort => $field) {
    foreach (['asc', 'desc'] as $dir) {
        [$c, $d] = call('GET', "/api/leaderboard?sort=$sort&dir=$dir", null, $jarG);
        $vals = $c === 200 ? array_column($d['players'], $field) : [];
        $sorted = $vals;
        $field === 'username' ? usort($sorted, 'strcasecmp') : sort($sorted);
        if ($dir === 'desc') $sorted = array_reverse($sorted);
        check("leaderboard sort=$sort dir=$dir", $c === 200 && $vals === $sorted);
    }
}
[$c] = call('GET', '/api/leaderboard?sort=password_hash&dir=asc', null, $jarG);
check('invalid sort field -> 400', $c === 400);
[$c] = call('GET', '/api/leaderboard?sort=score&dir=sideways', null, $jarG);
check('invalid direction -> 400', $c === 400);
[$c] = call('GET', '/api/leaderboard?sort=score;DROP%20TABLE%20users&dir=asc', null, $jarG);
check('SQL in sort parameter -> 400', $c === 400);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
