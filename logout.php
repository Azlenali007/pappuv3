<?php
/**
 * Logout Script
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::logout();
set_flash('info', 'You have been logged out safely.');
redirect('/login.php');
