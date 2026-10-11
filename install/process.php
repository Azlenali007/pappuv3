<?php
/**
 * Installer Processing Handler
 * SMM Panel - PHP 8+
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (file_exists(__DIR__ . '/installed.lock')) {
    header('Location: /install/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /install/index.php?step=2');
    exit;
}

// 1. Gather Database Parameters
$dbHost = trim($_POST['db_host'] ?? 'localhost');
$dbPort = trim($_POST['db_port'] ?? '3306');
$dbName = trim($_POST['db_name'] ?? '');
$dbUser = trim($_POST['db_user'] ?? '');
$dbPass = (string)($_POST['db_pass'] ?? '');

// 2. Gather Admin Account Parameters
$adminName = trim($_POST['admin_name'] ?? 'Administrator');
$adminEmail = strtolower(trim($_POST['admin_email'] ?? ''));
$adminUsername = trim($_POST['admin_username'] ?? 'admin');
$adminPassword = (string)($_POST['admin_password'] ?? '');
$confirmPassword = (string)($_POST['admin_password_confirm'] ?? '');

// 3. Gather Website Parameters
$siteName = trim($_POST['site_name'] ?? 'SMM Panel');
$siteUrl = rtrim(trim($_POST['site_url'] ?? 'http://localhost:3000'), '/');
$timezone = trim($_POST['timezone'] ?? 'Asia/Kolkata');

// Validation
$errors = [];
if (empty($dbName) || empty($dbUser)) {
    $errors[] = 'Database name and username are required.';
}
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid administrator email address is required.';
}
if (empty($adminUsername) || strlen($adminUsername) < 3) {
    $errors[] = 'Admin username must be at least 3 characters long.';
}
if (strlen($adminPassword) < 8) {
    $errors[] = 'Admin password must be at least 8 characters long.';
}
if ($adminPassword !== $confirmPassword) {
    $errors[] = 'Admin passwords do not match.';
}

if (!empty($errors)) {
    $_SESSION['install_error'] = implode(' ', $errors);
    header('Location: /install/index.php?step=3');
    exit;
}

try {
    // 1. Connect to MySQL / MariaDB via PDO
    $dsnHost = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];

    try {
        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, $options);
    } catch (PDOException $e) {
        $pdoHost = new PDO($dsnHost, $dbUser, $dbPass, $options);
        $pdoHost->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, $options);
    }

    // 2. Execute database/schema.sql
    $schemaFile = dirname(__DIR__) . '/database/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Database schema file not found at database/schema.sql");
    }
    $schemaSql = file_get_contents($schemaFile);

    // Require functions for split_sql_statements
    require_once dirname(__DIR__) . '/includes/functions.php';
    $statements = split_sql_statements($schemaSql);
    foreach ($statements as $stmt) {
        if (trim($stmt) !== '') {
            $pdo->exec($stmt);
        }
    }

    // 3. Create or update Administrator Account
    $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
    $adminApiKey = 'adm_' . bin2hex(random_bytes(14));

    $pdo->prepare("DELETE FROM users WHERE username = :uname OR email = :email")->execute([
        'uname' => $adminUsername,
        'email' => $adminEmail
    ]);

    $insertAdmin = $pdo->prepare("
        INSERT INTO users (username, email, password, role, status, api_key, email_verified, balance)
        VALUES (:uname, :email, :pass, 'admin', 'active', :key, 1, 10000.0000)
    ");
    $insertAdmin->execute([
        'uname' => $adminUsername,
        'email' => $adminEmail,
        'pass' => $hashedPassword,
        'key' => $adminApiKey
    ]);

    // 4. Update Website Settings
    $settingsUpdate = [
        'site_name' => $siteName,
        'site_url' => $siteUrl,
        'timezone' => $timezone
    ];
    $stmtSet = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = :v2");
    foreach ($settingsUpdate as $k => $v) {
        $stmtSet->execute(['k' => $k, 'v' => $v, 'v2' => $v]);
    }

    // 5. Generate / Update includes/config.php
    $configFile = dirname(__DIR__) . '/includes/config.php';
    $configContent = "<?php\n"
        . "/**\n"
        . " * Application Configuration\n"
        . " * Generated automatically by SMM Panel Web Installer\n"
        . " */\n\n"
        . "declare(strict_types=1);\n\n"
        . "defined('APP_ROOT') or define('APP_ROOT', dirname(__DIR__));\n\n"
        . "date_default_timezone_set(" . var_export($timezone, true) . ");\n\n"
        . "define('APP_ENV', 'production');\n"
        . "ini_set('display_errors', '0');\n"
        . "error_reporting(0);\n\n"
        . "// Centralized Database Credentials\n"
        . "define('DB_HOST', " . var_export($dbHost, true) . ");\n"
        . "define('DB_PORT', " . var_export($dbPort, true) . ");\n"
        . "define('DB_NAME', " . var_export($dbName, true) . ");\n"
        . "define('DB_USER', " . var_export($dbUser, true) . ");\n"
        . "define('DB_PASS', " . var_export($dbPass, true) . ");\n\n"
        . "// Website Configuration\n"
        . "define('APP_NAME', " . var_export($siteName, true) . ");\n"
        . "define('APP_TAGLINE', 'Grow Your Social Media');\n"
        . "define('APP_URL', " . var_export($siteUrl, true) . ");\n"
        . "define('APP_CURRENCY', '₹');\n"
        . "define('APP_CURRENCY_CODE', 'INR');\n\n"
        . "// Payment Gateway Defaults\n"
        . "define('RAZORPAY_KEY_ID', 'rzp_test_YourKeyHere');\n"
        . "define('RAZORPAY_KEY_SECRET', 'YourSecretKeyHere');\n\n"
        . "// Session Security Startup\n"
        . "if (session_status() === PHP_SESSION_NONE) {\n"
        . "    ini_set('session.cookie_httponly', '1');\n"
        . "    ini_set('session.use_only_cookies', '1');\n"
        . "    ini_set('session.cookie_samesite', 'Lax');\n"
        . "    if (isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on') {\n"
        . "        ini_set('session.cookie_secure', '1');\n"
        . "    }\n"
        . "    session_start();\n"
        . "}\n";

    file_put_contents($configFile, $configContent);

    // 6. Create Installation Lock
    $lockFile = __DIR__ . '/installed.lock';
    $lockData = json_encode([
        'installed_at' => date('Y-m-d H:i:s'),
        'admin_username' => $adminUsername,
        'admin_email' => $adminEmail,
        'site_name' => $siteName
    ], JSON_PRETTY_PRINT);
    file_put_contents($lockFile, $lockData);

    // Redirect to Success Page
    header('Location: /install/success.php');
    exit;

} catch (Exception $e) {
    $_SESSION['install_error'] = 'Installation failed: ' . $e->getMessage();
    header('Location: /install/index.php?step=3');
    exit;
}
