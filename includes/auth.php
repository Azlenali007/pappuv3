<?php
/**
 * Authentication and Session Management
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

class Auth {
    public static function check(): bool {
        return !empty($_SESSION['user_id']);
    }

    public static function user(): ?array {
        if (!self::check()) {
            return null;
        }

        static $cachedUser = null;
        if ($cachedUser !== null) {
            return $cachedUser;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, username, email, phone, balance, spent, role, status, api_key, created_at FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $cachedUser = $stmt->fetch() ?: null;

        if (!$cachedUser || $cachedUser['status'] !== 'active') {
            self::logout();
            return null;
        }

        return $cachedUser;
    }

    public static function id(): ?int {
        return $_SESSION['user_id'] ?? null;
    }

    public static function isAdmin(): bool {
        $u = self::user();
        return $u && $u['role'] === 'admin';
    }

    public static function requireLogin(): void {
        if (!self::check()) {
            set_flash('error', 'Please log in to continue.');
            redirect('/login.php');
        }
    }

    public static function requireAdmin(): void {
        self::requireLogin();
        if (!self::isAdmin()) {
            set_flash('error', 'Access denied. Administrator privileges required.');
            redirect('/user/dashboard.php');
        }
    }

    public static function login(string $email, string $password): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, password, status, role FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => strtolower(trim($email))]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] !== 'active') {
                return false;
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_role'] = $user['role'];
            return true;
        }

        return false;
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}
