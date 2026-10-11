<?php
/**
 * MySQL 8+ / MariaDB Database Connection via PDO
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    DB_HOST,
                    DB_PORT,
                    DB_NAME
                );
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                return self::$instance;
            } catch (PDOException $e) {
                error_log("Database connection failure: " . $e->getMessage());
                // If installer not completed or DB not initialized, redirect to web installer
                if (!file_exists(dirname(__DIR__) . '/install/installed.lock')) {
                    header("Location: /install/index.php");
                    exit;
                }
                throw $e;
            }
        }
        return self::$instance;
    }
}
