<?php
declare(strict_types=1);
// Imports the five-letter word dataset into the `words` table.
// Usage: php database/seed_words.php [answers_file] [allowed_file]
// Defaults: data/answers.txt (possible targets) and data/allowed.txt (extra allowed guesses).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/api/lib/db.php';

/** Read one word per line; normalise to lower case, drop duplicates and anything not exactly 5 letters. */
function load_words(string $file): array
{
    if (!is_readable($file)) { fwrite(STDERR, "Cannot read $file\n"); exit(1); }
    $words = [];
    $rejected = 0;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $w = strtolower(trim($line));
        if ($w === '' || $w[0] === '#') continue;
        if (!preg_match('/^[a-z]{5}$/', $w)) { $rejected++; continue; }
        $words[$w] = true;
    }
    return [array_keys($words), $rejected];
}

$root = dirname(__DIR__);
[$answers, $badA] = load_words($argv[1] ?? "$root/data/answers.txt");
[$extra,   $badE] = load_words($argv[2] ?? "$root/data/allowed.txt");
$answerSet = array_flip($answers);
$all = array_unique(array_merge($answers, $extra));

$pdo = db();
$pdo->beginTransaction();
$pdo->exec('DELETE FROM words');
$ins = $pdo->prepare('INSERT INTO words (word, is_answer) VALUES (?, ?)');
foreach ($all as $w) { $ins->execute([$w, isset($answerSet[$w]) ? 1 : 0]); }
$pdo->commit();

printf("Imported %d words (%d possible answers). Rejected %d invalid entries.\n",
    count($all), count($answers), $badA + $badE);
