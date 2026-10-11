-- Migration 008: Professional Floating User Voting System Architecture
-- SMM Panel Production Migration

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

-- User dismissals of voting bubble
CREATE TABLE IF NOT EXISTS `user_poll_bubble_dismissals` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `poll_id` INT UNSIGNED NOT NULL,
  `dismissed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_poll_dismissal` (`user_id`, `poll_id`),
  INDEX `idx_user_dismissal` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed an initial active published community poll if none exists
INSERT INTO `polls` (`id`, `question`, `description`, `status`, `results_visibility`, `allow_change`, `starts_at`, `ends_at`)
SELECT 1, 'Which platform services should we prioritize next?', 'Help us choose the next major social platform to expand with ultra-fast delivery and lower prices.', 'published', 'after_vote', 1, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 14 DAY)
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `polls` WHERE `id` = 1);

INSERT INTO `poll_options` (`id`, `poll_id`, `option_text`, `sort_order`)
SELECT 1, 1, 'Instagram Threads & Broadcast Channels', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `poll_options` WHERE `id` = 1);

INSERT INTO `poll_options` (`id`, `poll_id`, `option_text`, `sort_order`)
SELECT 2, 1, 'YouTube 4K Watch Hours & Monetization Pack', 2 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `poll_options` WHERE `id` = 2);

INSERT INTO `poll_options` (`id`, `poll_id`, `option_text`, `sort_order`)
SELECT 3, 1, 'Telegram Boosts & Premium Channel Members', 3 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `poll_options` WHERE `id` = 3);

INSERT INTO `poll_options` (`id`, `poll_id`, `option_text`, `sort_order`)
SELECT 4, 1, 'TikTok Live Stream Viewers & Comments', 4 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `poll_options` WHERE `id` = 4);
