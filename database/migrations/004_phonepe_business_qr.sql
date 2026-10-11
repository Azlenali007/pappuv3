-- Migration 004: PhonePe Business QR & Gmail Payment Verification Engine
-- SMM Panel Production Gateway Addition

-- 1. Create Payment Verification Logs Table for Gmail reconciliation audit trail
CREATE TABLE IF NOT EXISTS `payment_verification_logs` (
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

-- 2. Seed PhonePe Business QR Gateway into payment_methods
INSERT INTO `payment_methods` (`name`, `code`, `type`, `icon`, `min_amount`, `max_amount`, `fee_percent`, `instructions`, `config_data`, `status`, `mode`, `sort_order`)
VALUES (
  'PhonePe Business QR',
  'phonepe_business_qr',
  'manual',
  '🟣',
  10.0000,
  100000.0000,
  0.00,
  'Scan the PhonePe Business QR code with any UPI app (PhonePe, Google Pay, Paytm, etc.). After successful payment, enter the 12-digit UTR/Reference ID from your payment receipt.',
  '{"merchant_id":"","upi_id":"","qr_image_url":"","gmail_method":"imap","gmail_email":"","gmail_app_password":"","gmail_client_id":"","gmail_client_secret":"","gmail_refresh_token":"","sender_whitelist":"noreply@phonepe.com,merchant-care@phonepe.com","auto_verify_on_submit":1,"mode":"test"}',
  'inactive',
  'test',
  6
)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `type` = VALUES(`type`),
  `icon` = VALUES(`icon`);
