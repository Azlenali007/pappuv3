-- Migration 007: Complete Flash Sale System Architecture
-- SMM Panel Production Migration

CREATE TABLE IF NOT EXISTS `flash_sales` (
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
  INDEX `idx_flash_status_dates` (`status`, `starts_at`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safely add flash sale tracking columns to orders table if not present
SET @dbname = DATABASE();
SET @tablename = "orders";
SET @columnname = "flash_sale_id";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `orders` ADD COLUMN `flash_sale_id` INT UNSIGNED NULL AFTER `service_id`, ADD COLUMN `discount_amount` DECIMAL(10, 4) NOT NULL DEFAULT 0.0000 AFTER `charge`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Seed default active flash sale if none exists
INSERT INTO `flash_sales` (`id`, `title`, `subtitle`, `badge_text`, `discount_type`, `discount_value`, `scope_type`, `category_id`, `service_ids`, `starts_at`, `ends_at`, `status`, `max_orders`, `orders_count`, `banner_bg`)
SELECT 1, 'Mega Weekend Flash Sale', 'Boost your accounts with limited-time discounts on top Instagram & social growth services!', '⚡ 30% OFF', 'percentage', 30.0000, 'category', 1, NULL, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 3 DAY), 'active', 500, 14, 'from-blue-600 via-indigo-600 to-purple-600'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `flash_sales` WHERE `id` = 1);
