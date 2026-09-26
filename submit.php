<?php
// Method Machine Studio — form handler for the waitlist (index.html) and Stage 1 intake (apply.html).
// Appends each submission to a private CSV and emails the owner. Responds with JSON.
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function reply(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    reply(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$notify   = (string)($config['notify_email'] ?? '');
$from     = (string)($config['from_email'] ?? '');
$dataDir  = (string)($config['data_dir'] ?? (dirname(__DIR__) . '/mms-data'));

// Honeypot: bots fill the hidden "website" field. Pretend success so they move on.
if (trim((string)($_POST['website'] ?? '')) !== '') {
    reply(200, ['ok' => true]);
}

// Strip control characters and cap length; neutralise spreadsheet formula injection.
function clean(string $key, int $max = 500): string {
    $v = trim((string)($_POST[$key] ?? ''));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    $v = mb_substr($v, 0, $max);
    if ($v !== '' && strpbrk($v[0], '=+-@') !== false) {
        $v = "'" . $v;
    }
    return $v;
}

// ---- Stage 1 auto-scoring (first pass for evaluators; the key lives in answer_key.php, never in the page) ----

// Every number in a free-text answer; "3/8" -> 0.375, "56.6%" -> 0.566, "10,975.61" -> 10975.61.
function numbers_in(string $text): array {
    preg_match_all('/(-?\d[\d,]*(?:\.\d+)?|-?\.\d+)\s*(?:\/\s*(\d+(?:\.\d+)?))?\s*(%)?/', $text, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $v = (float)str_replace(',', '', $x[1]);
        if (!empty($x[2]) && (float)$x[2] != 0.0) $v /= (float)$x[2];
        if (!empty($x[3])) $v /= 100;
        $out[] = $v;
    }
    return $out;
}

function score_attempt(array $answers): array {
    $keyFile = __DIR__ . '/answer_key.php';
    if (!is_file($keyFile)) {
        return ['character_line' => '', 'hard_fail_line' => '', 'aptitude_line' => '',
                'report' => "Auto-scoring off: answer_key.php is not on the server.\n"];
    }
    $key = require $keyFile;
    $pick = fn(string $id): string => strtoupper(trim((string)($answers[$id] ?? '')));

    // Character gate
    $preferred = 0; $seen = 0; $fails = []; $lines = [];
    foreach ($key['character'] as $id => $k) {
        $a = $pick($id);
        if ($a === '') { $lines[] = "  $id: —"; continue; }
        $seen++;
        if ($a === $k['preferred']) $preferred++;
        $tag = $a === $k['preferred'] ? 'preferred' : (in_array($a, $k['hard_fail'], true) ? 'HARD FAIL' : 'other');
        if ($tag === 'HARD FAIL') $fails[] = $id;
        $lines[] = "  $id: $a ($tag)";
    }
    $flags = [];
    if (in_array($pick('A1'), ['A', 'D'], true)) $flags[] = 'A1 ' . $pick('A1') . ': hard fail if no discomfort shown elsewhere in the set';
    if ($pick('A1') === 'C' && $pick('A8') === 'C') $flags[] = 'C on both A1 and A8: pattern of aestheticized untruth (hard fail per rubric)';
    $theater = array_filter(['A3', 'A4', 'A10', 'A11'], fn($id) => in_array($pick($id), ['C', 'D'], true));
    if (count($theater) >= 3) $flags[] = 'C/D cluster on ' . implode(', ', $theater) . ': "founder-max" theater';
    if ($pick('A11') === 'D') $flags[] = 'A11 D: honesty-as-brand (soft flag)';
    $gate = count($fails) <= 1 && count($theater) < 3 ? 'PASS (pending portrait)' : 'HOLD';

    // Aptitude, auto-markable items only
    $bySec = []; $got = 0; $max = 0; $marks = [];
    foreach ($key['mcq'] as $id => $ok) {
        $a = $pick($id); $right = $a !== '' && in_array($a, $ok, true);
        $sec = $id[0]; $bySec[$sec] = ($bySec[$sec] ?? [0, 0]); $bySec[$sec][1]++; $max++;
        if ($right) { $bySec[$sec][0]++; $got++; }
        $marks[] = "  $id: " . ($a === '' ? '—' : $a) . ($right ? ' ✓' : ' ✗') . ' (key ' . implode('/', $ok) . ')';
    }
    foreach ($key['numeric'] as $id => $targets) {
        $text = (string)($answers[$id] ?? '');
        $nums = numbers_in($text);
        $right = $text !== '';
        foreach ($targets as [$want, $tol]) {
            $hit = false;
            foreach ($nums as $n) if (abs($n - $want) <= $tol) { $hit = true; break; }
            $right = $right && $hit;
        }
        $sec = $id[0]; $bySec[$sec] = ($bySec[$sec] ?? [0, 0]); $bySec[$sec][1]++; $max++;
        if ($right) { $bySec[$sec][0]++; $got++; }
        $marks[] = "  $id: " . ($text === '' ? '—' : '"' . mb_substr($text, 0, 60) . '"') . ($right ? ' ✓' : ' ✗');
    }
    ksort($bySec);
    $secLine = implode('  ', array_map(fn($s, $v) => "$s {$v[0]}/{$v[1]}", array_keys($bySec), $bySec));
    $pct = $max ? round(100 * $got / $max) : 0;

    $manual = [];
    foreach ($key['manual'] as $id) {
        $manual[] = "  $id: " . (trim((string)($answers[$id] ?? '')) === '' ? '(blank)' : trim((string)$answers[$id]));
    }

    $report = "CHARACTER GATE (Section A): $gate\n"
            . "Preferred answers: $preferred / 12 (answered $seen)\n"
            . 'Hard fails: ' . ($fails ? implode(', ', $fails) : 'none') . "\n"
            . ($flags ? "Flags:\n  " . implode("\n  ", $flags) . "\n" : '')
            . implode("\n", $lines) . "\n\n"
            . "APTITUDE, auto-marked items only: $got / $max ($pct%)\n$secLine\n"
            . "Short answers are matched on the numbers only; confirm them and score the working (0–2) by hand.\n"
            . implode("\n", $marks) . "\n\n"
            . "FOR MANUAL SCORING\n" . implode("\n", $manual) . "\n"
            . "Working for each item is in the answers column of applications.csv (keys ending in ~work).\n";

    return [
        'character_line' => "$preferred/12",
        'hard_fail_line' => $fails ? implode(' ', $fails) : 'none',
        'aptitude_line'  => "$got/$max",
        'report'         => $report,
    ];
}

$type  = clean('type', 20) === 'application' ? 'application' : 'waitlist';
$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    reply(422, ['ok' => false, 'error' => 'invalid_email']);
}

if (!is_dir($dataDir) && !@mkdir($dataDir, 0750, true)) {
    reply(500, ['ok' => false, 'error' => 'storage_unavailable']);
}

$ip   = $_SERVER['REMOTE_ADDR'] ?? '';
$when = gmdate('c');

// Simple per-IP rate limit: at most 10 submissions per 10 minutes.
$rateFile = $dataDir . '/.rate-' . hash('sha256', $ip) ;
$recent = [];
if (is_file($rateFile)) {
    $recent = array_filter(array_map('intval', explode("\n", (string)file_get_contents($rateFile))),
        fn($t) => $t > time() - 600);
}
if (count($recent) >= 10) {
    reply(429, ['ok' => false, 'error' => 'rate_limited']);
}
$recent[] = time();
@file_put_contents($rateFile, implode("\n", $recent), LOCK_EX);

if ($type === 'waitlist') {
    $file   = $dataDir . '/waitlist.csv';
    $header = ['submitted_at', 'email', 'page', 'ip', 'user_agent'];
    $row    = [$when, $email, clean('page', 200), $ip, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300)];
    $subject = 'New waitlist signup: ' . $email;
    $body    = "Email: $email\nTime (UTC): $when\n";
} else {
    $answers = json_decode((string)($_POST['answers'] ?? ''), true);
    $answers = is_array($answers) ? $answers : [];
    $score   = score_attempt($answers);

    $file   = $dataDir . '/applications.csv';
    $header = ['submitted_at', 'candidate', 'name', 'email', 'work', 'source', 'mode', 'timed_out', 'answered', 'focus_events',
               'character_preferred', 'hard_fails', 'aptitude_auto', 'answers', 'ip'];
    $row    = [$when, clean('candidate', 40), clean('name', 200), $email, clean('work', 2000), clean('source', 300),
               clean('mode', 20), clean('timed_out', 1), clean('answered', 6), clean('focus_events', 6),
               $score['character_line'], $score['hard_fail_line'], $score['aptitude_line'], clean('answers', 60000), $ip];
    $subject = 'New Stage 1 application: ' . clean('name', 200);
    $body    = "Name: {$row[2]}\nEmail: $email\nCandidate: {$row[1]}\nMode: {$row[6]}\n"
             . "Answered: {$row[8]}  Timed out: {$row[7]}  Focus events: {$row[9]}\n"
             . "Source: {$row[5]}\n\nWork / note:\n{$row[4]}\n\n"
             . $score['report'];
}

$fh = @fopen($file, 'ab');
if ($fh === false || !flock($fh, LOCK_EX)) {
    reply(500, ['ok' => false, 'error' => 'storage_unavailable']);
}
if (filesize($file) === 0) {
    fputcsv($fh, $header);
}
fputcsv($fh, $row);
flock($fh, LOCK_UN);
fclose($fh);

if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
    $headers = [];
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'From: Method Machine Studio <' . $from . '>';
    }
    $headers[] = 'Reply-To: ' . $email;
    $headers[] = 'Content-Type: text/plain; charset=utf-8';
    @mail($notify, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}

reply(200, ['ok' => true]);
