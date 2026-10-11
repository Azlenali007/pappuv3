<?php
/**
 * Installer Database Connection Tester
 * SMM Panel - PHP 8+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

if (file_exists(__DIR__ . '/installed.lock')) {
    echo json_encode(['success' => false, 'message' => 'Installer is locked.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$host = trim($_POST['db_host'] ?? 'localhost');
$port = trim($_POST['db_port'] ?? '3306');
$name = trim($_POST['db_name'] ?? '');
$user = trim($_POST['db_user'] ?? '');
$pass = (string)($_POST['db_pass'] ?? '');

if (empty($name) || empty($user)) {
    echo json_encode(['success' => false, 'message' => 'Database name and username are required.']);
    exit;
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo json_encode(['success' => true, 'message' => 'Connected successfully!']);
} catch (PDOException $e) {
    try {
        $dsnHost = "mysql:host={$host};port={$port};charset=utf8mb4";
        $pdo = new PDO($dsnHost, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        echo json_encode(['success' => true, 'message' => 'Server connected! Database will be created if needed.']);
    } catch (PDOException $ex) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
