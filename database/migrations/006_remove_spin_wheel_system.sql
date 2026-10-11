-- =====================================================================
-- Migration 006: Safe Removal of Spin & Win / Spin Wheel System
-- =====================================================================
-- Description:
-- Safely tears down Spin & Win feature objects while strictly preserving
-- user wallet accounting balances, transaction ledger auditing trails,
-- orders, payments, services, and unrelated configuration data.
--
-- Safety Guarantees:
-- 1. Does NOT delete or modify user balances (`users.balance`).
-- 2. Does NOT delete financial records from `transactions`. Historical spin
--    bets and payouts (types 'spin_bet', 'spin_win' or notes) remain intact
--    in the transactions ledger for financial audit trails.
-- 3. Safely cleans up feature-specific configuration settings.
-- 4. Safely drops dedicated Spin Wheel tables with proper foreign key order.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Remove Spin Wheel settings from the settings table
DELETE FROM `settings` WHERE `key` IN (
  'spin_wheel_enabled',
  'spin_wheel_max_reward_limit',
  'spin_wheel_cooldown_seconds'
);

-- 2. Drop Spin Wheel administrative audit logs
DROP TABLE IF EXISTS `spin_wheel_audit_logs`;

-- 3. Drop Spin Wheel configuration snapshot versions
DROP TABLE IF EXISTS `spin_wheel_config_versions`;

-- 4. Drop Spin Wheel reward outcome segments
DROP TABLE IF EXISTS `spin_wheel_outcomes`;

-- 5. Drop Spin Wheel bet amount tiers
DROP TABLE IF EXISTS `spin_wheel_bets`;

-- 6. Spin Spins Execution Ledger:
-- Note: 'spin_spins' recorded individual spin attempts. If your organization
-- requires historical spin records for gaming/lottery compliance audits, you
-- may keep this table as an archive. Otherwise, the statement below safely drops it.
DROP TABLE IF EXISTS `spin_spins`;

-- 7. Audit trail notice for transactions table:
-- The `transactions` table column `type` may continue to store existing historical
-- records with type 'spin_bet' or 'spin_win' so financial statements balance.
-- If you wish to strictly revert the enum to the default set once past transactions
-- are archived, run:
-- ALTER TABLE `transactions` MODIFY COLUMN `type` ENUM('deposit', 'order', 'refund', 'bonus', 'admin_adjustment') NOT NULL;

SET FOREIGN_KEY_CHECKS = 1;
