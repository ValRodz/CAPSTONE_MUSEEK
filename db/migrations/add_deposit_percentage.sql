-- Migration: Add deposit_percentage column to studios table
-- Date: 2025-11-19
-- Purpose: Allow studio owners to configure their initial deposit percentage for bookings

-- Add deposit_percentage column to studios table
ALTER TABLE studios 
ADD COLUMN deposit_percentage DECIMAL(5,2) NOT NULL DEFAULT 25.00 
COMMENT 'Percentage of total booking amount required as initial deposit (0-100)' 
AFTER StudioImg;

-- Add constraint to ensure percentage is between 0 and 100
ALTER TABLE studios 
ADD CONSTRAINT chk_deposit_percentage 
CHECK (deposit_percentage >= 0 AND deposit_percentage <= 100);

-- Set existing studios to 25% (current hardcoded behavior)
UPDATE studios SET deposit_percentage = 25.00 WHERE deposit_percentage = 0 OR deposit_percentage IS NULL;

-- Verify the changes
SELECT 'Migration completed successfully!' AS status;
SELECT StudioID, StudioName, deposit_percentage 
FROM studios 
LIMIT 5;

