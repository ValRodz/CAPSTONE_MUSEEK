-- ============================================================
-- Migration: Add Instructor Blocked Dates (Simple Approach)
-- Description: Add a simple text column to store blocked dates
--              as comma-separated values (YYYY-MM-DD format)
-- Date: 2025-11-19
-- ============================================================

-- Drop the complex instructor_schedules table if it exists
DROP TABLE IF EXISTS `instructor_schedules`;

-- Add blocked_dates column to instructors table
ALTER TABLE `instructors`
ADD COLUMN `blocked_dates` TEXT DEFAULT NULL
COMMENT 'Comma-separated list of blocked dates in YYYY-MM-DD format';

-- Verify the change
SELECT 'blocked_dates column added to instructors table successfully' AS Status;

