<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "=== REESTR DIAGNOSTICS ===\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n";
echo 'PHP_VERSION: ' . PHP_VERSION . "\n";
echo 'SAPI: ' . PHP_SAPI . "\n";
echo 'Document root: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'n/a') . "\n";
echo 'Script dir: ' . __DIR__ . "\n\n";

echo "=== EXTENSIONS ===\n";
$ext = ['pdo', 'pdo_mysql', 'mbstring', 'fileinfo', 'openssl'];
foreach ($ext as $e) {
    echo $e . ': ' . (extension_loaded($e) ? 'yes' : 'no') . "\n";
}
echo "\n";

echo "=== CONFIG LOAD ===\n";
try {
    require __DIR__ . '/config.php';
    echo "config.php: OK\n";
    if (defined('SCANS_STORAGE_PATH')) {
        $p = (string)SCANS_STORAGE_PATH;
        echo "SCANS_STORAGE_PATH: " . $p . "\n";
        echo "scans path exists: " . (is_dir($p) ? 'yes' : 'no') . "\n";
        echo "scans path writable: " . (is_writable($p) ? 'yes' : 'no') . "\n";
    } else {
        echo "SCANS_STORAGE_PATH: not defined\n";
    }
} catch (Throwable $e) {
    echo "config.php: FAIL\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
echo "\n";

echo "=== INDEX BOOTSTRAP TEST ===\n";
echo "Trying to require index.php...\n";

try {
    require __DIR__ . '/index.php';
    echo "index.php require: OK\n";
} catch (Throwable $e) {
    echo "index.php require: FAIL\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo 'File: ' . $e->getFile() . "\n";
    echo 'Line: ' . $e->getLine() . "\n";
}

