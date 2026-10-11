-- Migration 005: Spin Wheel System Architecture
-- SMM Panel Production Feature Integration
-- Supports configurable bet amounts, customizable segment outcomes (jackpot, monetary reward, money back, try again),
-- immutable configuration version snapshots, atomic spin ledger with unique idempotency keys,
-- and administrative settings audit logs.

-- 1. Ensure transactions type enum supports 'spin_bet' and 'spin_win'
ALTER TABLE `transactions`
  MODIFY COLUMN `type` ENUM('deposit', 'order', 'refund', 'bonus', 'admin_adjustment', 'spin_bet', 'spin_win') NOT NULL;

-- 2. Spin Wheel Bet Amounts & Configurations Table
CREATE TABLE IF NOT EXISTS `spin_wheel_bets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bet_amount` DECIMAL(12, 4) NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_spin_bet_amount` (`bet_amount`),
  INDEX `idx_spin_bet_status` (`is_enabled`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Spin Wheel Reward Outcomes Table
CREATE TABLE IF NOT EXISTS `spin_wheel_outcomes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bet_id` INT UNSIGNED NOT NULL,
  `reward_type` ENUM('jackpot', 'reward', 'money_back', 'no_reward') NOT NULL DEFAULT 'reward',
  `label` VARCHAR(80) NOT NULL,
  `reward_amount` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `probability` DECIMAL(6, 3) NOT NULL DEFAULT 0.000, -- e.g. 15.500% (precision up to 3 decimals)
  `icon_key` VARCHAR(40) NOT NULL DEFAULT 'trophy',
  `color_bg` VARCHAR(30) NOT NULL DEFAULT '#3b82f6',
  `color_text` VARCHAR(30) NOT NULL DEFAULT '#ffffff',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bet_id`) REFERENCES `spin_wheel_bets`(`id`) ON DELETE CASCADE,
  INDEX `idx_outcome_bet_order` (`bet_id`, `display_order`),
  INDEX `idx_outcome_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Spin Wheel Active Configuration Snapshots (Immutable versioning for spins)
CREATE TABLE IF NOT EXISTS `spin_wheel_config_versions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bet_id` INT UNSIGNED NOT NULL,
  `version_hash` VARCHAR(64) NOT NULL,
  `outcomes_snapshot` JSON NOT NULL,
  `total_probability` DECIMAL(6, 3) NOT NULL DEFAULT 100.000,
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_spin_cfg_bet` (`bet_id`),
  INDEX `idx_spin_cfg_hash` (`version_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Spins Transaction Ledger Table
CREATE TABLE IF NOT EXISTS `spin_spins` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid` VARCHAR(64) NOT NULL UNIQUE,
  `user_id` INT UNSIGNED NOT NULL,
  `bet_id` INT UNSIGNED NOT NULL,
  `bet_amount` DECIMAL(12, 4) NOT NULL,
  `outcome_id` INT UNSIGNED NULL,
  `reward_type` ENUM('jackpot', 'reward', 'money_back', 'no_reward') NOT NULL,
  `outcome_label` VARCHAR(80) NOT NULL,
  `reward_amount` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `segment_index` INT UNSIGNED NOT NULL DEFAULT 0,
  `final_angle` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
  `config_version_id` INT UNSIGNED NULL,
  `bet_transaction_id` INT UNSIGNED NULL,
  `reward_transaction_id` INT UNSIGNED NULL,
  `status` ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'completed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`bet_id`) REFERENCES `spin_wheel_bets`(`id`) ON DELETE RESTRICT,
  INDEX `idx_spins_user_created` (`user_id`, `created_at`),
  INDEX `idx_spins_reward_status` (`reward_type`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Spin Wheel Audit Logs Table
CREATE TABLE IF NOT EXISTS `spin_wheel_audit_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT UNSIGNED NULL,
  `action` VARCHAR(60) NOT NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_spin_audit_admin` (`admin_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Seed Initial Global Settings
INSERT INTO `settings` (`key`, `value`)
VALUES 
  ('spin_wheel_enabled', '1'),
  ('spin_wheel_max_reward_limit', '1000.0000'),
  ('spin_wheel_cooldown_seconds', '3')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- 8. Seed Default Bet Amounts (₹1 and ₹5)
INSERT INTO `spin_wheel_bets` (`id`, `bet_amount`, `is_enabled`, `display_order`)
VALUES 
  (1, 1.0000, 1, 1),
  (2, 5.0000, 1, 2)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

-- 9. Seed Default Outcomes for Bet ₹1 (8 segments totaling exactly 100.000%)
-- Segments:
-- 0: ₹30 JACKPOT (0.500%)
-- 1: TRY AGAIN (35.000%)
-- 2: ₹2 (15.000%)
-- 3: MONEY BACK (25.000%)
-- 4: ₹5 (4.500%)
-- 5: BETTER LUCK (15.000%)
-- 6: ₹10 (2.000%)
-- 7: ₹1 (3.000%)
-- Sum: 0.5 + 35 + 15 + 25 + 4.5 + 15 + 2 + 3 = 100.000%
INSERT INTO `spin_wheel_outcomes` (`id`, `bet_id`, `reward_type`, `label`, `reward_amount`, `probability`, `icon_key`, `color_bg`, `color_text`, `is_enabled`, `display_order`)
VALUES
  (1, 1, 'jackpot', '₹30 JACKPOT', 30.0000, 0.500, 'trophy', '#7c3aed', '#ffffff', 1, 0),
  (2, 1, 'no_reward', 'TRY AGAIN', 0.0000, 35.000, 'cross', '#1e293b', '#94a3b8', 1, 1),
  (3, 1, 'reward', '₹2 Reward', 2.0000, 15.000, 'coin', '#2563eb', '#ffffff', 1, 2),
  (4, 1, 'money_back', 'MONEY BACK', 1.0000, 25.000, 'refresh', '#059669', '#ffffff', 1, 3),
  (5, 1, 'reward', '₹5 Reward', 5.0000, 4.500, 'gift', '#d97706', '#ffffff', 1, 4),
  (6, 1, 'no_reward', 'BETTER LUCK', 0.0000, 15.000, 'cross', '#334155', '#94a3b8', 1, 5),
  (7, 1, 'reward', '₹10 Reward', 10.0000, 2.000, 'sparkles', '#4f46e5', '#ffffff', 1, 6),
  (8, 1, 'money_back', '₹1 Return', 1.0000, 3.000, 'refresh', '#0d9488', '#ffffff', 1, 7)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

-- 10. Seed Default Outcomes for Bet ₹5 (8 segments totaling exactly 100.000%)
-- Segments:
-- 9:  ₹80 JACKPOT (0.500%)
-- 10: TRY AGAIN (30.000%)
-- 11: ₹10 (15.000%)
-- 12: MONEY BACK (25.000%)
-- 13: ₹20 (5.000%)
-- 14: BETTER LUCK (15.000%)
-- 15: ₹50 (1.500%)
-- 16: ₹5 Return (8.000%)
-- Sum: 0.5 + 30 + 15 + 25 + 5 + 15 + 1.5 + 8 = 100.000%
INSERT INTO `spin_wheel_outcomes` (`id`, `bet_id`, `reward_type`, `label`, `reward_amount`, `probability`, `icon_key`, `color_bg`, `color_text`, `is_enabled`, `display_order`)
VALUES
  (9, 2, 'jackpot', '₹80 JACKPOT', 80.0000, 0.500, 'trophy', '#7c3aed', '#ffffff', 1, 0),
  (10, 2, 'no_reward', 'TRY AGAIN', 0.0000, 30.000, 'cross', '#1e293b', '#94a3b8', 1, 1),
  (11, 2, 'reward', '₹10 Reward', 10.0000, 15.000, 'coin', '#2563eb', '#ffffff', 1, 2),
  (12, 2, 'money_back', 'MONEY BACK', 5.0000, 25.000, 'refresh', '#059669', '#ffffff', 1, 3),
  (13, 2, 'reward', '₹20 Reward', 20.0000, 5.000, 'gift', '#d97706', '#ffffff', 1, 4),
  (14, 2, 'no_reward', 'BETTER LUCK', 0.0000, 15.000, 'cross', '#334155', '#94a3b8', 1, 5),
  (15, 2, 'reward', '₹50 Reward', 50.0000, 1.500, 'sparkles', '#4f46e5', '#ffffff', 1, 6),
  (16, 2, 'money_back', '₹5 Return', 5.0000, 8.000, 'refresh', '#0d9488', '#ffffff', 1, 7)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);
