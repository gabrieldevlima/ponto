-- Migration: Add geographic fields to schools and support for multiple photos
-- Date: 2024-08-30
-- Description: 
--   1. Add lat, lng, radius_m to schools table for geolocation validation
--   2. Add attendance_photos table for multiple photos per check-in
--   3. Add additional_photos_count to attendance table

-- Add geographic fields to schools table
ALTER TABLE schools 
ADD COLUMN lat DECIMAL(10,7) NULL COMMENT 'Latitude for geolocation validation',
ADD COLUMN lng DECIMAL(10,7) NULL COMMENT 'Longitude for geolocation validation', 
ADD COLUMN radius_m INT DEFAULT 300 COMMENT 'Radius in meters for geolocation validation',
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Create attendance_photos table for multiple photos per check-in
CREATE TABLE IF NOT EXISTS attendance_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  sha256 VARCHAR(64) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_att_photos_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_attendance_photos_attendance ON attendance_photos(attendance_id);
CREATE INDEX idx_attendance_photos_sha256 ON attendance_photos(sha256);

-- Add additional fields to attendance table for multiple photos support
ALTER TABLE attendance 
ADD COLUMN additional_photos_count INT DEFAULT 0 COMMENT 'Number of additional photos beyond the main one',
ADD COLUMN face_score DECIMAL(5,3) NULL COMMENT 'Best face recognition score from multiple descriptors',
ADD COLUMN validation_notes JSON NULL COMMENT 'Validation details including face and geo checks';

-- Update install.sql would also need these changes for new installations