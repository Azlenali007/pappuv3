-- Migration 002: Payment Gateways, Notification Center & Safe Update Architecture
-- SMM Panel Production Update

-- 1. Ensure Announcements Table has targeting, priority, and icon fields
ALTER TABLE `announcements`
  ADD COLUMN IF NOT EXISTS `target_user_id` INT UNSIGNED NULL AFTER `target_audience`,
  ADD COLUMN IF NOT EXISTS `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `icon` VARCHAR(50) NULL AFTER `badge_text`;

-- 2. User Notification Reads Tracking Table
CREATE TABLE IF NOT EXISTS `user_notification_reads` (
  `user_id` INT UNSIGNED NOT NULL,
  `announcement_id` INT UNSIGNED NOT NULL,
  `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `announcement_id`),
  INDEX `idx_notif_user` (`user_id`),
  INDEX `idx_notif_announcement` (`announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Update Audit Logs Table for Safe GitHub Update Center
CREATE TABLE IF NOT EXISTS `update_audit_logs` (
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

-- 4. Payment Methods: add mode column if missing
ALTER TABLE `payment_methods`
  ADD COLUMN IF NOT EXISTS `mode` ENUM('test', 'live') NOT NULL DEFAULT 'test' AFTER `status`;

-- 5. Ensure All 5 Requested Gateways are present in payment_methods
INSERT INTO `payment_methods` (`name`, `code`, `type`, `icon`, `min_amount`, `max_amount`, `fee_percent`, `instructions`, `config_data`, `status`, `mode`, `sort_order`)
VALUES
('Razorpay', 'razorpay', 'automatic', '⚡', 100.0000, 50000.0000, 0.00, 'Automated checkout supporting UPI, Credit/Debit cards, Net Banking & Wallets.', '{"key_id":"rzp_test_YourKeyHere","key_secret":"","webhook_secret":"","mode":"test"}', 'active', 'test', 1),
('PayPal', 'paypal', 'automatic', '🅿️', 500.0000, 500000.0000, 3.50, 'Global payment checkout via PayPal account, Visa, MasterCard, and Amex.', '{"client_id":"","client_secret":"","webhook_id":"","mode":"sandbox"}', 'inactive', 'test', 2),
('PhonePe', 'phonepe', 'automatic', '🟣', 100.0000, 100000.0000, 0.00, 'Direct PhonePe UPI & QR automated payment with instant server callback.', '{"merchant_id":"","salt_key":"","salt_index":"1","mode":"sandbox"}', 'inactive', 'test', 3),
('Paytm', 'paytm', 'automatic', '📲', 50.0000, 100000.0000, 0.00, 'Paytm Gateway / UPI QR & Net Banking with automated verification.', '{"merchant_id":"","merchant_key":"","channel_id":"WEB","industry_type":"Retail","upi_id":"smmpanel@upi","mode":"staging"}', 'inactive', 'test', 4),
('Binance Pay', 'binance', 'automatic', '🟡', 500.0000, 1000000.0000, 1.00, 'Cryptocurrency checkout powered by Binance Pay (USDT, BTC, ETH, BUSD).', '{"api_key":"","secret_key":"","merchant_id":"","mode":"test"}', 'inactive', 'test', 5)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `type` = VALUES(`type`),
  `icon` = VALUES(`icon`);
