<?php
/**
 * Kontaktformular-Endpunkt fuer toniplattenleger.ch
 *
 * Nimmt das Formular per POST (JSON oder form-encoded) entgegen und versendet
 * die Anfrage ueber authentifiziertes SMTP an die Mailbox von Toni Plattenleger.
 *
 * Das SMTP-Passwort steht ausschliesslich in api/config.php auf dem Server.
 * Es taucht weder im Frontend noch in Git auf.
 *
 * Antwortet immer mit JSON: {"success":true|false,"message":"..."}
 */

declare(strict_types=1);

// ---------------------------------------------------------------- Konfiguration
// Bevorzugt ausserhalb des Web-Roots, damit die Datei selbst dann nicht
// ausgeliefert werden kann, wenn PHP einmal nicht laufen sollte.
// (Hoststar liefert ueber nginx aus — .htaccess greift dort nicht.)
$candidates = [
    dirname(__DIR__, 2) . '/tpl-mail-config.php',
    dirname(__DIR__) . '/../tpl-mail-config.php',
    __DIR__ . '/config.php',
];
$cfg = null;
foreach ($candidates as $candidate) {
    if (is_readable($candidate)) {
        $cfg = require $candidate;
        break;
    }
}
if (!is_array($cfg)) {
    error_log('[kontaktformular] Keine Konfiguration gefunden. Gesucht in: ' . implode(', ', $candidates));
    respond(500, false, 'Der Server ist nicht konfiguriert. Bitte kontaktieren Sie uns telefonisch.');
}

// ---------------------------------------------------------------------- CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Gleiche Herkunft (Formular und Endpunkt auf demselben Host) ist immer
// erlaubt — sonst braeuchte jede Variante wie www. einen Allowlist-Eintrag.
$originHost = $origin !== '' ? (string)parse_url($origin, PHP_URL_HOST) : '';
$serverHost = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$isSameOrigin = $originHost !== '' && strcasecmp($originHost, (string)$serverHost) === 0;

if ($origin !== '' && !$isSameOrigin) {
    if (!in_array($origin, $cfg['allowed_origins'], true)) {
        respond(403, false, 'Diese Herkunft ist nicht freigegeben.');
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, false, 'Nur POST wird unterstuetzt.');
}

// ------------------------------------------------------------------- Eingaben
$raw = file_get_contents('php://input') ?: '';
$data = [];
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $decoded = json_decode($raw, true);
    $data = is_array($decoded) ? $decoded : [];
} else {
    $data = $_POST;
}

$name    = clean($data['name']    ?? '', 120);
$email   = clean($data['email']   ?? '', 180);
$phone   = clean($data['phone']   ?? '', 60);
$betreff = clean($data['betreff'] ?? '', 80);
$message = trim((string)($data['message'] ?? ''));
$honey   = trim((string)($data['botcheck'] ?? ''));
$ts      = (string)($data['ts'] ?? '');

// ----------------------------------------------------------------- Spamschutz
// 1. Honeypot — fuer Menschen unsichtbar, Bots fuellen ihn aus.
if ($honey !== '') {
    respond(200, true, 'Vielen Dank!');   // Bot glauben lassen, es habe geklappt
}

// 2. Zeitfalle — sofort abgeschickt oder uralt = kein echter Besucher.
if (ctype_digit($ts)) {
    $ageSeconds = time() - (int)floor(((int)$ts) / 1000);
    if ($ageSeconds < (int)$cfg['min_seconds_on_page'] || $ageSeconds > (int)$cfg['max_form_age_hours'] * 3600) {
        respond(429, false, 'Bitte laden Sie die Seite neu und versuchen Sie es erneut.');
    }
}

// 3. Rate-Limit pro IP.
if (!withinRateLimit($cfg)) {
    respond(429, false, 'Zu viele Anfragen. Bitte versuchen Sie es spaeter erneut oder rufen Sie uns an.');
}

// ---------------------------------------------------------------- Validierung
$errors = [];
if ($name === '')                                        { $errors[] = 'Name fehlt.'; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL))          { $errors[] = 'E-Mail-Adresse ist ungueltig.'; }
if (mb_strlen($message) < 5)                             { $errors[] = 'Nachricht fehlt.'; }
if (mb_strlen($message) > 5000)                          { $errors[] = 'Nachricht ist zu lang.'; }
if ($errors) {
    respond(422, false, implode(' ', $errors));
}

// ------------------------------------------------------------------- Nachricht
$subject = 'Neue Anfrage ueber toniplattenleger.ch'
         . ($betreff !== '' ? ' — ' . $betreff : '');

$lines = [
    'Neue Anfrage ueber das Kontaktformular',
    str_repeat('=', 40),
    '',
    'Name:     ' . $name,
    'E-Mail:   ' . $email,
    'Telefon:  ' . ($phone !== '' ? $phone : '—'),
    'Betreff:  ' . ($betreff !== '' ? $betreff : '—'),
    '',
    'Nachricht:',
    $message,
    '',
    str_repeat('-', 40),
    'Gesendet: ' . date('d.m.Y H:i:s'),
    'IP:       ' . clientIp(),
];
$body = implode("\r\n", $lines);

$ok = sendMail($cfg, $subject, $body, $email, $name, $err);

if ($ok) {
    recordSend();
    respond(200, true, 'Vielen Dank! Wir melden uns innerhalb von 24 Stunden.');
}

error_log('[kontaktformular] SMTP fehlgeschlagen: ' . $err);
respond(502, false, 'Die Nachricht konnte nicht gesendet werden.', $cfg['debug'] ? $err : null);


/* ============================== Funktionen ============================== */

/** Trimmt, kappt die Laenge und entfernt CR/LF (Header-Injection). */
function clean($value, int $max): string
{
    $v = str_replace(["\r", "\n", "\0"], ' ', trim((string)$value));
    return mb_substr($v, 0, $max);
}

function clientIp(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
}

function rateLimitFile(): string
{
    return sys_get_temp_dir() . '/tpl_contact_' . sha1(clientIp()) . '.txt';
}

/** Hoechstens N Absendungen pro IP und Stunde. */
function withinRateLimit(array $cfg): bool
{
    $file = rateLimitFile();
    if (!is_readable($file)) {
        return true;
    }
    $stamps = array_filter(
        array_map('intval', explode(',', (string)file_get_contents($file))),
        static fn(int $t): bool => $t > time() - 3600
    );
    return count($stamps) < (int)$cfg['rate_limit_per_hour'];
}

function recordSend(): void
{
    $file = rateLimitFile();
    $stamps = is_readable($file)
        ? array_filter(
            array_map('intval', explode(',', (string)file_get_contents($file))),
            static fn(int $t): bool => $t > time() - 3600
          )
        : [];
    $stamps[] = time();
    @file_put_contents($file, implode(',', $stamps), LOCK_EX);
}

/** RFC 2047 fuer Kopfzeilen mit Umlauten. */
function encodeHeader(string $text): string
{
    return preg_match('/[\x80-\xFF]/', $text)
        ? '=?UTF-8?B?' . base64_encode($text) . '?='
        : $text;
}

/** Liest eine — auch mehrzeilige — SMTP-Antwort. */
function smtpRead($fp): string
{
    $out = '';
    while (($line = fgets($fp, 515)) !== false) {
        $out .= $line;
        // Letzte Zeile einer Antwort hat an Position 3 ein Leerzeichen, kein '-'.
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    return $out;
}

/** Sendet einen Befehl und prueft den erwarteten Antwortcode. */
function smtpCmd($fp, ?string $cmd, string $expect, string $label, ?string &$err): bool
{
    if ($cmd !== null) {
        fwrite($fp, $cmd . "\r\n");
    }
    $res = smtpRead($fp);
    if (strncmp($res, $expect, strlen($expect)) !== 0) {
        // Nur das Label ausgeben — der Befehl kann Zugangsdaten enthalten.
        $err = 'Schritt "' . $label . '": erwartet ' . $expect . ', erhalten: ' . trim($res);
        return false;
    }
    return true;
}

/** Baut die Mail und stellt sie ueber authentifiziertes SMTP zu. */
function sendMail(array $cfg, string $subject, string $body, string $replyTo, string $replyName, ?string &$err): bool
{
    $err = null;
    $host = ($cfg['smtp_secure'] === 'ssl' ? 'ssl://' : '') . $cfg['smtp_host'];

    $ctx = stream_context_create(['ssl' => [
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'allow_self_signed' => false,
    ]]);

    $fp = @stream_socket_client(
        $host . ':' . (int)$cfg['smtp_port'],
        $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx
    );
    if (!$fp) {
        $err = 'Verbindung fehlgeschlagen (' . $errno . '): ' . $errstr;
        return false;
    }
    stream_set_timeout($fp, 20);

    $helo = $cfg['smtp_host'];
    $from = $cfg['mail_from'];
    $to   = $cfg['mail_to'];

    $headers = implode("\r\n", [
        'Date: ' . date('r'),
        'From: ' . encodeHeader((string)$cfg['from_name']) . ' <' . $from . '>',
        'To: '   . encodeHeader((string)$cfg['to_name'])   . ' <' . $to . '>',
        'Reply-To: ' . encodeHeader($replyName) . ' <' . $replyTo . '>',
        'Subject: ' . encodeHeader($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $cfg['smtp_host'] . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        // base64 umgeht Zeilenlaengen- und Punkt-am-Zeilenanfang-Probleme
        'Content-Transfer-Encoding: base64',
        'X-Mailer: toniplattenleger.ch Kontaktformular',
    ]);
    $payload = $headers . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");

    // Label statt Befehl, damit nie Zugangsdaten in einer Fehlermeldung landen.
    $steps = [
        [null,                                     '220', 'Begruessung'],
        ['EHLO ' . $helo,                          '250', 'EHLO'],
        ['AUTH LOGIN',                             '334', 'AUTH LOGIN'],
        [base64_encode((string)$cfg['smtp_user']), '334', 'Benutzername'],
        [base64_encode((string)$cfg['smtp_pass']), '235', 'Passwort'],
        ['MAIL FROM:<' . $from . '>',              '250', 'MAIL FROM'],
        ['RCPT TO:<' . $to . '>',                  '250', 'RCPT TO'],
        ['DATA',                                   '354', 'DATA'],
        [$payload . "\r\n.",                       '250', 'Nachricht'],
    ];

    foreach ($steps as [$cmd, $expect, $label]) {
        if (!smtpCmd($fp, $cmd, $expect, $label, $err)) {
            @fwrite($fp, "QUIT\r\n");
            @fclose($fp);
            return false;
        }
    }

    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return true;
}

/** Beendet die Anfrage mit einer JSON-Antwort. */
function respond(int $status, bool $success, string $message, ?string $detail = null): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $out = ['success' => $success, 'message' => $message];
    if ($detail !== null) {
        $out['detail'] = $detail;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
