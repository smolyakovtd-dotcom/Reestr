<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* =======================
   ERROR REPORTING
   (на проде лучше выключить)
======================= */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* =======================
   CONFIG (DB + SMTP + AUTH)
======================= */
const DB_HOST = 'localhost';
const DB_NAME = 'cf449082_main';
const DB_USER = 'cf449082_main';
const DB_PASS = 'summer';

// === SMTP Timeweb ===
const SMTP_HOST = 'smtp.timeweb.ru';
const SMTP_PORT = 465; // SSL
const SMTP_USER = 'abyss@reestr.tw1.ru';
const SMTP_PASS = 'r26f0614U';
const SMTP_FROM = SMTP_USER;
const SMTP_FROM_NAME = 'Реестр документов';

// Пароли входа по ролям (локальная сеть)
const AUTH_ROLE_PASSWORDS = [
    'warehouse' => 'summer', // Кладовщик: полный доступ
    'viewer'    => 'winter', // Просмотр: только чтение
    'scanner'   => 'spring', // Внесение сканов: чтение + upload/delete сканов
];

// Корневая директория сканов (можно UNC: \\fileserver\scans\reestr)
// Подробная инструкция: SCANS_SETUP.md
const SCANS_STORAGE_PATH = __DIR__ . '/storage/scans';
const SCANS_MAX_FILE_SIZE = 25 * 1024 * 1024; // 25MB

/* =======================
   SESSION
======================= */
session_name('registry_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/* =======================
   DB CONNECT
======================= */
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Ошибка подключения к MySQL: " . htmlspecialchars($e->getMessage()));
}

/* =======================
   GLOBALS
======================= */
$types = [
    'incoming'     => 'Входящие',
    'outgoing'     => 'Исходящие',
    'incoming_tk'  => 'Входящие ТК',
    'outgoing_tk'  => 'Исходящие ТК',
];

/* =======================
   CSRF + HELPERS
======================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token(): string {
    return $_SESSION['csrf_token'] ?? '';
}

function require_csrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('CSRF token invalid');
    }
}

function set_alert(string $msg): void {
    $_SESSION['alert'] = $msg;
}

function ymd_today(): string {
    return date('Y-m-d');
}

/* =======================
   PHPMailer LOADER (no composer)
======================= */
function load_phpmailer(): void {
    // Подключаем PHPMailer (без composer)
    $base = __DIR__ . '/assets/phpmailer/src/';
    require_once $base . 'Exception.php';
    require_once $base . 'PHPMailer.php';
    require_once $base . 'SMTP.php';
}

/* =======================
   SEND MAIL (SMTP)
======================= */
function smtp_send_text_mail(string $toEmail, string $subject, string $text): array {
    load_phpmailer();

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->Port       = SMTP_PORT;

        // SSL 465
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->Subject = $subject;
        $mail->Body    = $text;

        $mail->send();
        return ['success' => true, 'message' => 'Письмо отправлено!'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Ошибка отправки: ' . $e->getMessage()];
    }
}
