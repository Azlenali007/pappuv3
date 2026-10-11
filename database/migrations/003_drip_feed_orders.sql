-- Migration 003: Drip-feed Orders & Scheduled Execution
-- SMM Panel Production Migration

CREATE TABLE IF NOT EXISTS `drip_feed_orders` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `link` VARCHAR(512) NOT NULL,
  `quantity_per_run` INT UNSIGNED NOT NULL,
  `total_runs` INT UNSIGNED NOT NULL,
  `completed_runs` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_runs` INT UNSIGNED NOT NULL DEFAULT 0,
  `interval_minutes` INT UNSIGNED NOT NULL DEFAULT 60,
  `charge_per_run` DECIMAL(10, 4) NOT NULL,
  `total_charge` DECIMAL(10, 4) NOT NULL,
  `refunded_amount` DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
  `status` ENUM('queued', 'running', 'completed', 'partially_failed', 'cancelled', 'failed') NOT NULL DEFAULT 'queued',
  `last_run_at` DATETIME NULL,
  `next_run_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_drip_status_next` (`status`, `next_run_at`),
  INDEX `idx_drip_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drip_feed_runs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `drip_feed_id` INT UNSIGNED NOT NULL,
  `run_number` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED NULL,
  `status` ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
  `provider_order_id` VARCHAR(64) NULL,
  `error_message` TEXT NULL,
  `scheduled_at` DATETIME NOT NULL,
  `executed_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_drip_run_schedule` (`drip_feed_id`, `run_number`),
  INDEX `idx_drip_run_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
