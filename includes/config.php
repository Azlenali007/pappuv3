<?php
/**
 * Application Configuration
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

// Prevent direct access
defined('APP_ROOT') or define('APP_ROOT', dirname(__DIR__));

// Timezone and error reporting
date_default_timezone_set('Asia/Kolkata');

// App Environment ('production' or 'development')
define('APP_ENV', 'development');

if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// Database Credentials
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'smm_panel');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// App URL and Name
define('APP_NAME', 'SMM Panel');
define('APP_TAGLINE', 'Grow Your Social Media');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost:3000');
define('APP_CURRENCY', '₹');
define('APP_CURRENCY_CODE', 'INR');

// Razorpay Payment Gateway Credentials
define('RAZORPAY_KEY_ID', getenv('RAZORPAY_KEY_ID') ?: 'rzp_test_YourKeyHere');
define('RAZORPAY_KEY_SECRET', getenv('RAZORPAY_KEY_SECRET') ?: 'YourSecretKeyHere');

// Session security configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}
