# Ponto System - Database Migration Guide

## How to apply the database migration

1. **For existing installations**, run the migration SQL:
   ```bash
   mysql -u root -p your_database_name < migrations/001_add_geographic_fields_and_multiple_photos.sql
   ```

2. **For new installations**, the updated `install.sql` already includes all the new fields.

## Migration 001: Geographic Fields and Multiple Photos

This migration adds:

### Schools table enhancements:
- `lat` DECIMAL(10,7) - Latitude for geolocation validation
- `lng` DECIMAL(10,7) - Longitude for geolocation validation  
- `radius_m` INT DEFAULT 300 - Radius in meters for geolocation validation
- `updated_at` TIMESTAMP - Auto-update timestamp

### Attendance table enhancements:
- `additional_photos_count` INT DEFAULT 0 - Number of additional photos beyond the main one
- `face_score` DECIMAL(5,3) - Best face recognition score from multiple descriptors
- `validation_notes` JSON - Validation details including face and geo checks

### New attendance_photos table:
- Stores additional photos for each attendance record
- Includes SHA256 hashes for integrity verification
- Foreign key relationship with attendance table

## Features Added

1. **Geographic validation**: Check-in validation based on school location and radius
2. **Multiple photos**: Support for uploading additional photos during check-in
3. **Enhanced face recognition**: Compare against multiple descriptors for better accuracy
4. **Audit trails**: Detailed validation notes for debugging and compliance