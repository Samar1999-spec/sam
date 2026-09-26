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
    $file   = $dataDir . '/applications.csv';
    $header = ['submitted_at', 'candidate', 'name', 'email', 'work', 'source', 'mode', 'timed_out', 'answered', 'focus_events', 'answers', 'ip'];
    $row    = [$when, clean('candidate', 40), clean('name', 200), $email, clean('work', 2000), clean('source', 300),
               clean('mode', 20), clean('timed_out', 1), clean('answered', 6), clean('focus_events', 6), clean('answers', 20000), $ip];
    $subject = 'New Stage 1 application: ' . clean('name', 200);
    $body    = "Name: {$row[2]}\nEmail: $email\nCandidate: {$row[1]}\nMode: {$row[6]}\n"
             . "Answered: {$row[8]}  Timed out: {$row[7]}  Focus events: {$row[9]}\n"
             . "Source: {$row[5]}\n\nWork / note:\n{$row[4]}\n";
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
