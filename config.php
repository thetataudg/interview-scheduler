<?php
// config.php - central configuration
session_start();

$dbFile = __DIR__ . '/database.sqlite';
if (!file_exists($dbFile)) {
    // If DB doesn't exist, try to create it from schema.sql automatically (convenience)
    if (file_exists(__DIR__ . '/schema.sql')) {
        $out = shell_exec('sqlite3 ' . escapeshellarg($dbFile) . ' < ' . escapeshellarg(__DIR__ . '/schema.sql') . ' 2>&1');
        // ignore output; if creation fails, subsequent PDO will throw
    }
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB error: " . htmlspecialchars($e->getMessage()));
}

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function is_admin_logged() {
    return !empty($_SESSION['is_admin']);
}

function require_admin() {
    if (!is_admin_logged()) {
        header('Location: admin.php');
        exit;
    }
}
?>
