-- =====================================================================
-- SMM PANEL DATABASE SCHEMA (MySQL 8.0+ / MariaDB 10.5+)
-- UTF8mb4 Full Unicode Support, Strict Foreign Keys, Performance Indexes
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `update_audit_logs`;
DROP TABLE IF EXISTS `migrations`;
DROP TABLE IF EXISTS `user_poll_bubble_dismissals`;
DROP TABLE IF EXISTS `poll_votes`;
DROP TABLE IF EXISTS `poll_options`;
DROP TABLE IF EXISTS `polls`;
DROP TABLE IF EXISTS `user_notification_reads`;
DROP TABLE IF EXISTS `user_announcement_dismissals`;
DROP TABLE IF EXISTS `announcements`;
DROP TABLE IF EXISTS `referral_commissions`;
DROP TABLE IF EXISTS `referrals`;
DROP TABLE IF EXISTS `payment_methods`;
DROP TABLE IF EXISTS `currencies`;
DROP TABLE IF EXISTS `ticket_messages`;
DROP TABLE IF EXISTS `tickets`;
DROP TABLE IF EXISTS `transactions`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `services`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `providers`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `settings`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. SETTINGS TABLE
CREATE TABLE `settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `value` TEXT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. USERS TABLE
CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(32) NULL,
  `balance` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `spent` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `role` ENUM('user', 'admin', 'support') NOT NULL DEFAULT 'user',
  `status` ENUM('active', 'suspended', 'banned') NOT NULL DEFAULT 'active',
  `api_key` VARCHAR(64) NOT NULL UNIQUE,
  `referral_code` VARCHAR(32) NULL UNIQUE,
  `referred_by` INT UNSIGNED NULL,
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_status` (`status`),
  INDEX `idx_users_referral_code` (`referral_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. PROVIDERS TABLE (External API providers)
CREATE TABLE `providers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `api_url` VARCHAR(255) NOT NULL,
  `api_key` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. CATEGORIES TABLE
CREATE TABLE `categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `platform` ENUM('instagram', 'youtube', 'telegram', 'facebook', 'tiktok', 'twitter', 'other') NOT NULL DEFAULT 'other',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_category_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. SERVICES TABLE
CREATE TABLE `services` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NULL,
  `provider_service_id` VARCHAR(64) NULL,
  `name` VARCHAR(255) NOT NULL,
  `rate_per_1000` DECIMAL(10, 4) NOT NULL,
  `original_rate` DECIMAL(10, 4) NULL,
  `min_quantity` INT UNSIGNED NOT NULL DEFAULT 10,
  `max_quantity` INT UNSIGNED NOT NULL DEFAULT 100000,
  `service_type` ENUM('default', 'custom_comments', 'package', 'poll') NOT NULL DEFAULT 'default',
  `speed` VARCHAR(100) DEFAULT 'Fast Delivery',
  `description` TEXT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE SET NULL,
  INDEX `idx_service_status` (`status`),
  INDEX `idx_service_provider_mapping` (`provider_id`, `provider_service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. ORDERS TABLE
CREATE TABLE `orders` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NULL,
  `provider_order_id` VARCHAR(64) NULL,
  `link` VARCHAR(512) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `charge` DECIMAL(10, 4) NOT NULL,
  `discount_amount` DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
  `flash_sale_id` INT UNSIGNED NULL,
  `start_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `remains` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('pending', 'processing', 'in_progress', 'completed', 'partial', 'cancelled') NOT NULL DEFAULT 'pending',
  `mode` ENUM('manual', 'auto') NOT NULL DEFAULT 'auto',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE RESTRICT,
  INDEX `idx_orders_status` (`status`),
  INDEX `idx_orders_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. TRANSACTIONS TABLE
CREATE TABLE `transactions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED NULL,
  `type` ENUM('deposit', 'order', 'refund', 'bonus', 'admin_adjustment') NOT NULL,
  `amount` DECIMAL(12, 4) NOT NULL,
  `gateway` VARCHAR(50) NOT NULL DEFAULT 'system',
  `gateway_txn_id` VARCHAR(120) NULL,
  `status` ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'completed',
  `note` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
  INDEX `idx_txn_type` (`type`),
  INDEX `idx_txn_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. TICKETS TABLE
CREATE TABLE `tickets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED NULL,
  `subject` VARCHAR(191) NOT NULL,
  `status` ENUM('open', 'in_progress', 'closed') NOT NULL DEFAULT 'open',
  `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_tickets_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. TICKET_MESSAGES TABLE
CREATE TABLE `ticket_messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. ANNOUNCEMENTS / POPUP NOTIFICATIONS TABLE
CREATE TABLE `announcements` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `type` ENUM('announcement', 'service', 'maintenance', 'offer', 'telegram', 'update', 'system') NOT NULL DEFAULT 'announcement',
  `badge_text` VARCHAR(64) DEFAULT 'Important Notice',
  `icon` VARCHAR(50) NULL,
  `message` TEXT NOT NULL,
  `btn_text` VARCHAR(100) NULL,
  `btn_link` VARCHAR(255) NULL,
  `target_audience` ENUM('all', 'active_users') NOT NULL DEFAULT 'all',
  `target_user_id` INT UNSIGNED NULL,
  `show_once` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
  `starts_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_announcements_status` (`status`),
  INDEX `idx_announcements_target_user` (`target_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. USER ANNOUNCEMENT DISMISSALS TABLE
CREATE TABLE `user_announcement_dismissals` (
  `user_id` INT UNSIGNED NOT NULL,
  `announcement_id` INT UNSIGNED NOT NULL,
  `dismissed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `announcement_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11b. USER NOTIFICATION READS TABLE (Notification Center)
CREATE TABLE `user_notification_reads` (
  `user_id` INT UNSIGNED NOT NULL,
  `announcement_id` INT UNSIGNED NOT NULL,
  `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `announcement_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11c. DATABASE MIGRATIONS TRACKING TABLE
CREATE TABLE `migrations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `migration` VARCHAR(255) NOT NULL UNIQUE,
  `batch` INT UNSIGNED NOT NULL DEFAULT 1,
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11d. APPLICATION UPDATE AUDIT LOGS TABLE
CREATE TABLE `update_audit_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `version_from` VARCHAR(50) NOT NULL,
  `version_to` VARCHAR(50) NOT NULL,
  `started_at` DATETIME NOT NULL,
  `completed_at` DATETIME NULL,
  `backup_status` ENUM('pending', 'success', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
  `backup_file` VARCHAR(255) NULL,
  `validation_status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `migration_status` ENUM('pending', 'success', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
  `install_status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `health_check_status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `rollback_status` ENUM('none', 'pending', 'success', 'failed') NOT NULL DEFAULT 'none',
  `status` ENUM('in_progress', 'success', 'failed', 'rolled_back') NOT NULL DEFAULT 'in_progress',
  `details` TEXT NULL,
  `error_message` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. REFERRALS TABLE
CREATE TABLE `referrals` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `referrer_id` INT UNSIGNED NOT NULL,
  `referred_id` INT UNSIGNED NOT NULL UNIQUE,
  `referral_code` VARCHAR(32) NOT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `total_commission` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referred_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_referrals_referrer` (`referrer_id`),
  INDEX `idx_referrals_code` (`referral_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. REFERRAL COMMISSIONS TABLE
CREATE TABLE `referral_commissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `referrer_id` INT UNSIGNED NOT NULL,
  `referred_id` INT UNSIGNED NOT NULL,
  `transaction_id` INT UNSIGNED NULL,
  `order_id` INT UNSIGNED NULL,
  `event_type` ENUM('first_deposit', 'deposit', 'first_order', 'order') NOT NULL DEFAULT 'deposit',
  `source_amount` DECIMAL(12, 4) NOT NULL,
  `commission_rate` DECIMAL(8, 2) NOT NULL,
  `commission_amount` DECIMAL(12, 4) NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
  `note` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referred_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_ref_comm_status` (`status`),
  INDEX `idx_ref_comm_referrer` (`referrer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. CURRENCIES TABLE (Dynamic Multi-Currency System)
CREATE TABLE `currencies` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(10) NOT NULL UNIQUE,
  `symbol` VARCHAR(10) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `exchange_rate` DECIMAL(14, 6) NOT NULL DEFAULT 1.000000,
  `is_base` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_currency_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. PAYMENT METHODS TABLE (Production Multi-Gateway Architecture)
CREATE TABLE `payment_methods` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `type` ENUM('automatic', 'manual') NOT NULL DEFAULT 'automatic',
  `icon` VARCHAR(10) NOT NULL DEFAULT '💳',
  `min_amount` DECIMAL(12, 4) NOT NULL DEFAULT 100.0000,
  `max_amount` DECIMAL(12, 4) NOT NULL DEFAULT 50000.0000,
  `fee_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
  `instructions` TEXT NULL,
  `config_data` TEXT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `mode` ENUM('test', 'live') NOT NULL DEFAULT 'test',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. PAYMENT VERIFICATION LOGS TABLE (Automated Reconciliation Audit Trail)
CREATE TABLE `payment_verification_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `transaction_id` INT UNSIGNED NULL,
  `gateway` VARCHAR(50) NOT NULL DEFAULT 'phonepe_business_qr',
  `utr_searched` VARCHAR(100) NULL,
  `amount_expected` DECIMAL(12, 4) NULL,
  `amount_found` DECIMAL(12, 4) NULL,
  `status` ENUM('matched_success', 'pending_no_match', 'amount_mismatch', 'duplicate_rejected', 'connection_error') NOT NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pvl_txn` (`transaction_id`),
  INDEX `idx_pvl_utr` (`utr_searched`),
  INDEX `idx_pvl_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. FLASH SALES TABLE
CREATE TABLE `flash_sales` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(191) NOT NULL,
  `subtitle` VARCHAR(255) NULL,
  `badge_text` VARCHAR(64) NOT NULL DEFAULT 'FLASH SALE',
  `discount_type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
  `discount_value` DECIMAL(10, 4) NOT NULL DEFAULT 20.0000,
  `scope_type` ENUM('all', 'category', 'services') NOT NULL DEFAULT 'all',
  `category_id` INT UNSIGNED NULL,
  `service_ids` TEXT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `status` ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
  `max_orders` INT UNSIGNED NULL,
  `orders_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `banner_bg` VARCHAR(64) NOT NULL DEFAULT 'from-blue-600 via-indigo-600 to-purple-600',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
  INDEX `idx_flash_status_dates` (`status`, `starts_at`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SEED INITIAL CONFIGURATION & DEFAULT DATA
-- =====================================================================
INSERT INTO `settings` (`key`, `value`) VALUES
('site_name', 'SMM Panel'),
('site_tagline', 'Grow Your Social Media'),
('currency', '₹'),
('currency_code', 'INR'),
('app_version', '1.0.0'),
('mass_order_enabled', '1'),
('dripfeed_enabled', '1'),
('razorpay_key_id', 'rzp_test_YourKeyHere'),
('razorpay_key_secret', 'YourSecretKeyHere'),
('min_deposit', '100'),
('max_deposit', '50000'),
('maintenance_mode', '0'),
('referral_enabled', '1'),
('referral_commission_type', 'percentage'),
('referral_commission_rate', '5.00'),
('referral_trigger_event', 'every_deposit'),
('referral_min_deposit', '100'),
('referral_max_commission', '1000'),
('referral_default_status', 'approved');

-- Default Seed Currencies (INR Base, USD, EUR, GBP, BRL)
INSERT INTO `currencies` (`code`, `symbol`, `name`, `exchange_rate`, `is_base`, `status`) VALUES
('INR', '₹', 'Indian Rupee', 1.000000, 1, 'active'),
('USD', '$', 'US Dollar', 0.011600, 0, 'active'),
('EUR', '€', 'Euro', 0.010800, 0, 'active'),
('GBP', '£', 'British Pound', 0.009200, 0, 'active'),
('BRL', 'R$', 'Brazilian Real', 0.065000, 0, 'active');

-- Default Seed Payment Gateways (Razorpay, PayPal, PhonePe, Paytm, Binance Pay, PhonePe Business QR)
INSERT INTO `payment_methods` (`name`, `code`, `type`, `icon`, `min_amount`, `max_amount`, `fee_percent`, `instructions`, `config_data`, `status`, `mode`, `sort_order`) VALUES
('Razorpay', 'razorpay', 'automatic', '⚡', 100.0000, 50000.0000, 0.00, 'Automated instant checkout supporting UPI, Credit/Debit cards, Net Banking & Wallets.', '{"key_id":"rzp_test_YourKeyHere","key_secret":"","webhook_secret":"","mode":"test"}', 'active', 'test', 1),
('PayPal', 'paypal', 'automatic', '🅿️', 500.0000, 500000.0000, 3.50, 'Global payment checkout via PayPal account, Visa, MasterCard, and Amex.', '{"client_id":"","client_secret":"","webhook_id":"","mode":"sandbox"}', 'inactive', 'test', 2),
('PhonePe', 'phonepe', 'automatic', '🟣', 100.0000, 100000.0000, 0.00, 'Direct PhonePe UPI & QR automated payment with instant server callback.', '{"merchant_id":"","salt_key":"","salt_index":"1","mode":"sandbox"}', 'inactive', 'test', 3),
('Paytm', 'paytm', 'automatic', '📲', 50.0000, 100000.0000, 0.00, 'Paytm Gateway / UPI QR & Net Banking with automated verification.', '{"merchant_id":"","merchant_key":"","channel_id":"WEB","industry_type":"Retail","upi_id":"smmpanel@upi","mode":"staging"}', 'inactive', 'test', 4),
('Binance Pay', 'binance', 'automatic', '🟡', 500.0000, 1000000.0000, 1.00, 'Cryptocurrency checkout powered by Binance Pay (USDT, BTC, ETH, BUSD).', '{"api_key":"","secret_key":"","merchant_id":"","mode":"test"}', 'inactive', 'test', 5),
('PhonePe Business QR', 'phonepe_business_qr', 'manual', '🟣', 10.0000, 100000.0000, 0.00, 'Scan the PhonePe Business QR code with any UPI app (PhonePe, Google Pay, Paytm, etc.). After successful payment, enter the 12-digit UTR/Reference ID from your payment receipt.', '{"merchant_id":"","upi_id":"","qr_image_url":"","gmail_method":"imap","gmail_email":"","gmail_app_password":"","gmail_client_id":"","gmail_client_secret":"","gmail_refresh_token":"","sender_whitelist":"noreply@phonepe.com,merchant-care@phonepe.com","auto_verify_on_submit":1,"mode":"test"}', 'inactive', 'test', 6);

-- Initial Admin Account: username: admin / password: password123
-- Initial User Account: username: aaris / password: password123
INSERT INTO `users` (`id`, `username`, `email`, `password`, `phone`, `balance`, `spent`, `role`, `status`, `api_key`, `referral_code`, `referred_by`, `email_verified`) VALUES
(1001, 'admin', 'admin@smmpanel.local', '$2y$12$R.O44hC46gE8i1a0MvV9EeeYvA5f9V7Z3d.gO8Z3D/a6F5e9qLhQW', '+91 98765 00000', 50000.0000, 0.0000, 'admin', 'active', 'adm_839f284c17b44d28e71c9902', 'REFADMIN1001', NULL, 1),
(1024, 'Aaris Ali', 'aarisali@gmail.com', '$2y$12$R.O44hC46gE8i1a0MvV9EeeYvA5f9V7Z3d.gO8Z3D/a6F5e9qLhQW', '+91 98765 43210', 850.5000, 155.0000, 'user', 'active', 'usr_749e192a63d91b87d21c4301', 'REFAARIS1024', NULL, 1);

-- Default Categories
INSERT INTO `categories` (`id`, `name`, `platform`, `sort_order`, `status`) VALUES
(1, 'Instagram Followers [Real & Active]', 'instagram', 1, 'active'),
(2, 'Instagram Likes & Engagements', 'instagram', 2, 'active'),
(3, 'Instagram Views & Reels', 'instagram', 3, 'active'),
(4, 'YouTube Views [High Retention]', 'youtube', 4, 'active'),
(5, 'Telegram Members [Real & Instant]', 'telegram', 5, 'active'),
(6, 'TikTok Followers & Views', 'tiktok', 6, 'active'),
(7, 'Facebook Page Likes & Followers', 'facebook', 7, 'active'),
(8, 'Twitter (X) Followers & Retweets', 'twitter', 8, 'active');

-- Default Services
INSERT INTO `services` (`id`, `category_id`, `name`, `rate_per_1000`, `min_quantity`, `max_quantity`, `speed`, `description`, `status`) VALUES
(101, 1, 'Instagram Followers [Real & Active Followers - High Quality]', 35.0000, 1000, 1000000, 'Starts in 1-2 Hours', 'High quality, non-drop real active followers. Instant start with 30-day auto-refill.', 'active'),
(102, 2, 'Instagram Likes [High Quality - Instant Start]', 20.0000, 100, 500000, 'Instant Start', 'Ultra-fast delivery of premium likes from active accounts worldwide.', 'active'),
(103, 3, 'Instagram Views & Reels [High Retention]', 15.0000, 1000, 10000000, 'Instant Start', 'Viral video engagement booster for Instagram Reels and videos.', 'active'),
(104, 2, 'Instagram Custom Comments [Positive Real Text]', 50.0000, 10, 10000, 'Fast Delivery', 'Custom written comments in English or your language.', 'active'),
(201, 4, 'YouTube Views [Real Views - High Retention]', 12.0000, 1000, 5000000, 'Fast Delivery', 'Monetization-safe high watch time views from search and suggested videos.', 'active'),
(202, 4, 'YouTube Subscribers [Non-Drop & Real Channels]', 180.0000, 50, 50000, 'Starts in 2-4 Hours', 'Permanent non-drop YouTube channel subscribers with lifetime warranty.', 'active'),
(301, 5, 'Telegram Members [Real & Active Members - Instant Start]', 45.0000, 500, 200000, 'Instant Start', 'Telegram channel and group members. Real accounts with 0% drop guarantee.', 'active'),
(401, 6, 'TikTok Followers [Real & Active Accounts]', 65.0000, 100, 200000, 'Instant Start', 'High quality TikTok followers worldwide. Account profile must be set to public.', 'active'),
(501, 7, 'Facebook Page Likes & Followers [High Quality - Non Drop]', 80.0000, 100, 500000, 'Instant Start', 'Worldwide page likes and followers with 30 days refill guarantee.', 'active'),
(601, 8, 'Twitter (X) Followers [Real Looking Accounts]', 95.0000, 100, 100000, 'Starts in 1 Hour', 'Active Twitter profiles with bio & avatars. Safe, stable and high quality.', 'active');

-- Default Initial Orders (#1024)
INSERT INTO `orders` (`id`, `user_id`, `service_id`, `link`, `quantity`, `charge`, `start_count`, `remains`, `status`, `created_at`) VALUES
(10254, 1024, 101, 'https://instagram.com/aarisali', 1000, 35.0000, 4200, 200, 'processing', '2025-05-12 16:32:00'),
(10253, 1024, 201, 'https://youtube.com/watch?v=smmGrowth123', 5000, 120.0000, 1240, 0, 'completed', '2025-05-11 18:10:00'),
(10252, 1024, 301, 'https://t.me/techgrowthindia', 2000, 90.0000, 1500, 120, 'processing', '2025-05-10 13:45:00'),
(10251, 1024, 102, 'https://instagram.com/p/C67890123', 1000, 20.0000, 850, 0, 'completed', '2025-05-09 19:20:00');

-- Default Transactions (#1024)
INSERT INTO `transactions` (`user_id`, `type`, `amount`, `gateway`, `gateway_txn_id`, `status`, `note`, `created_at`) VALUES
(1024, 'deposit', 500.0000, 'Razorpay', 'pay_rzp_8941720', 'completed', 'Added funds via Razorpay UPI', '2025-05-12 16:12:00'),
(1024, 'order', -35.0000, 'system', 'ord_10254', 'completed', 'Order #10254: Instagram Followers', '2025-05-12 16:32:00'),
(1024, 'deposit', 200.0000, 'Razorpay', 'pay_rzp_6291054', 'completed', 'Added funds via Razorpay Cards', '2025-05-10 11:20:00'),
(1024, 'order', -120.0000, 'system', 'ord_10253', 'completed', 'Order #10253: YouTube Views', '2025-05-10 18:15:00');

-- Default Support Tickets
INSERT INTO `tickets` (`id`, `user_id`, `order_id`, `subject`, `status`, `priority`, `created_at`) VALUES
(1024, 1024, 10254, 'Order not started yet', 'open', 'high', '2025-05-12 11:20:00'),
(1023, 1024, NULL, 'Payment issue with QR code', 'in_progress', 'medium', '2025-05-10 18:15:00'),
(1022, 1024, 10253, 'Service delivery speed query', 'closed', 'low', '2025-05-08 15:40:00'),
(1021, 1024, 10251, 'Wrong quantity query', 'closed', 'low', '2025-05-06 13:10:00');

-- Default Announcements (Shows modern popup on User Dashboard)
INSERT INTO `announcements` (`id`, `title`, `type`, `badge_text`, `message`, `btn_text`, `btn_link`, `target_audience`, `show_once`, `status`) VALUES
(1, 'Welcome to SMM Panel! Join Our Official Telegram Channel', 'telegram', 'Official Telegram', 'Get instant order updates, exclusive flash discount codes, and priority support in our official Telegram community. Join thousands of active creators today!', 'Join Telegram Channel', 'https://t.me/smmpanelofficial', 'all', 1, 'active'),
(2, '⚡ Special Offer: 10% Extra Balance on All UPI & Crypto Deposits', 'offer', 'Special Offer', 'Top up your account balance today and automatically receive an extra 10% bonus credit on all instant UPI and USDT deposits over ₹500.', 'Add Funds Now', '/user/add-funds.php', 'all', 1, 'inactive');

-- Default Flash Sales
INSERT INTO `flash_sales` (`id`, `title`, `subtitle`, `badge_text`, `discount_type`, `discount_value`, `scope_type`, `category_id`, `service_ids`, `starts_at`, `ends_at`, `status`, `max_orders`, `orders_count`, `banner_bg`) VALUES
(1, 'Mega Weekend Flash Sale', 'Grab instant viral boosts on top Instagram & social growth services before time runs out!', '⚡ 30% OFF', 'percentage', 30.0000, 'category', 1, NULL, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 3 DAY), 'active', 500, 14, 'from-blue-600 via-indigo-600 to-purple-600');

-- 15. POLLS & USER VOTING SYSTEM
CREATE TABLE IF NOT EXISTS `polls` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `question` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `status` ENUM('draft', 'published', 'paused', 'closed', 'cancelled', 'archived') NOT NULL DEFAULT 'draft',
  `results_visibility` ENUM('always', 'after_vote', 'after_close', 'hidden') NOT NULL DEFAULT 'after_vote',
  `allow_change` TINYINT(1) NOT NULL DEFAULT 0,
  `starts_at` DATETIME NULL,
  `ends_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_polls_status_dates` (`status`, `starts_at`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_options` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poll_id` INT UNSIGNED NOT NULL,
  `option_text` VARCHAR(255) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_poll_options_poll` (`poll_id`),
  CONSTRAINT `fk_poll_options_poll` FOREIGN KEY (`poll_id`) REFERENCES `polls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_votes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poll_id` INT UNSIGNED NOT NULL,
  `option_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_poll_user` (`poll_id`, `user_id`),
  INDEX `idx_poll_votes_poll` (`poll_id`),
  INDEX `idx_poll_votes_option` (`option_id`),
  INDEX `idx_poll_votes_user` (`user_id`),
  CONSTRAINT `fk_poll_votes_poll` FOREIGN KEY (`poll_id`) REFERENCES `polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poll_votes_option` FOREIGN KEY (`option_id`) REFERENCES `poll_options` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_poll_bubble_dismissals` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `poll_id` INT UNSIGNED NOT NULL,
  `dismissed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_poll_dismissal` (`user_id`, `poll_id`),
  INDEX `idx_user_dismissal` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Community Poll
INSERT INTO `polls` (`id`, `question`, `description`, `status`, `results_visibility`, `allow_change`, `starts_at`, `ends_at`) VALUES
(1, 'Which platform services should we prioritize next?', 'Help us choose the next major social platform to expand with ultra-fast delivery and lower prices.', 'published', 'after_vote', 1, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 14 DAY));

INSERT INTO `poll_options` (`id`, `poll_id`, `option_text`, `sort_order`) VALUES
(1, 1, 'Instagram Threads & Broadcast Channels', 1),
(2, 1, 'YouTube 4K Watch Hours & Monetization Pack', 2),
(3, 1, 'Telegram Boosts & Premium Channel Members', 3),
(4, 1, 'TikTok Live Stream Viewers & Comments', 4);


