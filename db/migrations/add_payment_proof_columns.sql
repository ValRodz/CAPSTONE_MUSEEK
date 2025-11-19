-- ============================================================
-- Migration: Add Payment Proof Columns to g_cash table
-- Description: Add columns for GCash payment submission tracking
-- Date: 2025-11-19
-- ============================================================

-- Modify g_cash table to be auto-increment for new payment records
ALTER TABLE `g_cash`
MODIFY COLUMN `GCashID` int(11) NOT NULL AUTO_INCREMENT;

-- Add columns for payment proof tracking to g_cash table
ALTER TABLE `g_cash`
ADD COLUMN IF NOT EXISTS `gcash_sender_number` VARCHAR(15) DEFAULT NULL COMMENT 'GCash sender mobile number',
ADD COLUMN IF NOT EXISTS `payment_proof_path` VARCHAR(255) DEFAULT NULL COMMENT 'Path to payment screenshot',
ADD COLUMN IF NOT EXISTS `payment_notes` TEXT DEFAULT NULL COMMENT 'Additional notes from customer',
ADD COLUMN IF NOT EXISTS `payment_submitted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When payment proof was submitted',
ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

-- Update existing g_cash table comment
ALTER TABLE `g_cash` COMMENT = 'GCash payment records including merchant and customer transactions';

-- Add index for faster lookups by reference number
CREATE INDEX IF NOT EXISTS `idx_ref_num` ON `g_cash` (`Ref_Num`);
CREATE INDEX IF NOT EXISTS `idx_sender_number` ON `g_cash` (`gcash_sender_number`);

-- Verify the changes
SELECT 'GCash payment proof columns added successfully' AS Status;

