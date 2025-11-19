-- ============================================================
-- Migration: Add Instructor/Staff Availability Schedules
-- Description: Separate scheduling system for instructor/staff
--              availability independent of studio time slots
-- Date: 2025-11-19
-- ============================================================

-- Drop the old approach if it exists
DROP TABLE IF EXISTS `schedule_instructors`;

-- Create instructor_schedules table
CREATE TABLE IF NOT EXISTS `instructor_schedules` (
  `InstructorScheduleID` int(11) NOT NULL AUTO_INCREMENT,
  `InstructorID` int(11) NOT NULL,
  `OwnerID` int(11) NOT NULL,
  `Schedule_Date` date NOT NULL,
  `Time_Start` time NOT NULL,
  `Time_End` time NOT NULL,
  `Status` enum('Available','Break','Day-Off','Occupied') NOT NULL DEFAULT 'Available',
  `Notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`InstructorScheduleID`),
  KEY `idx_instructor` (`InstructorID`),
  KEY `idx_owner` (`OwnerID`),
  KEY `idx_date` (`Schedule_Date`),
  KEY `idx_status` (`Status`),
  KEY `idx_instructor_date` (`InstructorID`, `Schedule_Date`),
  CONSTRAINT `fk_instructor_schedules_instructor` FOREIGN KEY (`InstructorID`) REFERENCES `instructors` (`InstructorID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_instructor_schedules_owner` FOREIGN KEY (`OwnerID`) REFERENCES `studio_owners` (`OwnerID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Manages instructor/staff availability schedules independent of studio time slots';

-- Verify the table was created
SELECT 'instructor_schedules table created successfully' AS Status;

