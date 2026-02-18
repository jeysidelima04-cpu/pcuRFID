-- ============================================
-- PCU RFID2 Database - Normalized Schema
-- ============================================
-- This schema follows database normalization principles (3NF/BCNF)
-- to reduce redundancy and improve data integrity while maintaining
-- full backward compatibility with existing queries.
-- ============================================

-- Create the database
CREATE DATABASE IF NOT EXISTS pcu_rfid2;
USE pcu_rfid2;

-- ============================================
-- CORE TABLES (Authentication & User Identity)
-- ============================================

-- Users table - Core authentication and identity
-- Contains only essential user authentication and identification data
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    course VARCHAR(255) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'Student') NOT NULL DEFAULT 'Student',
    status ENUM('Pending', 'Active', 'Locked') NOT NULL DEFAULT 'Pending',
    failed_attempts INT NOT NULL DEFAULT 0,
    last_login DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Profile data (denormalized for backward compatibility)
    -- These columns remain in users table to maintain existing queries
    profile_picture VARCHAR(255) DEFAULT NULL,
    profile_picture_uploaded_at DATETIME DEFAULT NULL,
    
    -- RFID data (denormalized for backward compatibility)
    -- These columns remain in users table to maintain existing queries
    rfid_uid VARCHAR(50) DEFAULT NULL,
    rfid_registered_at TIMESTAMP NULL DEFAULT NULL,
    
    -- Violation tracking (denormalized for backward compatibility)
    -- This column remains in users table to maintain existing queries
    violation_count INT NOT NULL DEFAULT 0,
    
    -- Indexes for performance
    INDEX idx_student_id (student_id),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_status (status),
    INDEX idx_profile_picture (profile_picture),
    UNIQUE KEY unique_rfid (rfid_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AUTHENTICATION TABLES
-- ============================================

-- Two-factor authentication codes table
CREATE TABLE IF NOT EXISTS twofactor_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset tokens table
CREATE TABLE IF NOT EXISTS password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_used (used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- RFID & ACCESS CONTROL TABLES
-- ============================================

-- RFID cards table (normalized)
-- Separates RFID card data from user identity
-- Note: rfid_uid remains in users table for backward compatibility
-- This table provides additional metadata and audit trail
CREATE TABLE IF NOT EXISTS rfid_cards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    rfid_uid VARCHAR(50) NOT NULL,
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unregistered_at TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    registered_by INT DEFAULT NULL COMMENT 'Admin who registered the card',
    unregistered_by INT DEFAULT NULL COMMENT 'Admin who unregistered the card',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (registered_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (unregistered_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_active_rfid_per_user (user_id, is_active),
    INDEX idx_rfid_uid (rfid_uid),
    INDEX idx_user_id (user_id),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Violations tracking table (normalized)
-- Records each violation incident with full audit trail
CREATE TABLE IF NOT EXISTS violations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    rfid_uid VARCHAR(50) NOT NULL COMMENT 'RFID scanned at gate (may differ from registered)',
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    violation_type ENUM('forgot_card', 'unauthorized_access', 'blocked_entry') NOT NULL DEFAULT 'forgot_card',
    gate_location VARCHAR(100) DEFAULT NULL COMMENT 'Which security gate',
    security_guard_id INT DEFAULT NULL COMMENT 'Guard who logged the violation',
    email_sent TINYINT(1) NOT NULL DEFAULT 0,
    email_sent_at TIMESTAMP NULL DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (security_guard_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_scanned_at (scanned_at),
    INDEX idx_violation_type (violation_type),
    INDEX idx_rfid_uid (rfid_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROFILE & MEDIA TABLES
-- ============================================

-- Student profiles table (normalized)
-- Separates profile metadata from user identity
-- Note: profile_picture remains in users table for backward compatibility
-- This table provides additional profile metadata and audit trail
CREATE TABLE IF NOT EXISTS student_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    profile_picture VARCHAR(255) DEFAULT NULL,
    profile_picture_uploaded_at DATETIME DEFAULT NULL,
    profile_picture_size INT DEFAULT NULL COMMENT 'File size in bytes',
    profile_picture_mime_type VARCHAR(50) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    emergency_contact VARCHAR(100) DEFAULT NULL,
    emergency_phone VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_profile (user_id),
    INDEX idx_profile_picture (profile_picture)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AUDIT & LOGGING TABLES
-- ============================================

-- System audit log (for compliance and security monitoring)
CREATE TABLE IF NOT EXISTS audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL COMMENT 'register_card, unregister_card, clear_violation, etc.',
    table_name VARCHAR(50) NOT NULL,
    record_id INT DEFAULT NULL,
    old_values TEXT DEFAULT NULL COMMENT 'JSON of old values',
    new_values TEXT DEFAULT NULL COMMENT 'JSON of new values',
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_table_name (table_name),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TRIGGERS FOR DATA SYNCHRONIZATION
-- ============================================
-- These triggers maintain backward compatibility by keeping
-- denormalized columns in users table synchronized with normalized tables

-- Trigger: Sync RFID registration to users table
DELIMITER $$
CREATE TRIGGER after_rfid_insert
AFTER INSERT ON rfid_cards
FOR EACH ROW
BEGIN
    IF NEW.is_active = 1 THEN
        UPDATE users 
        SET rfid_uid = NEW.rfid_uid, 
            rfid_registered_at = NEW.registered_at
        WHERE id = NEW.user_id;
    END IF;
END$$
DELIMITER ;

-- Trigger: Sync RFID unregistration to users table
DELIMITER $$
CREATE TRIGGER after_rfid_update
AFTER UPDATE ON rfid_cards
FOR EACH ROW
BEGIN
    IF NEW.is_active = 0 AND OLD.is_active = 1 THEN
        UPDATE users 
        SET rfid_uid = NULL, 
            rfid_registered_at = NULL
        WHERE id = NEW.user_id;
    END IF;
END$$
DELIMITER ;

-- Trigger: Sync violation count to users table
DELIMITER $$
CREATE TRIGGER after_violation_insert
AFTER INSERT ON violations
FOR EACH ROW
BEGIN
    UPDATE users 
    SET violation_count = (
        SELECT COUNT(*) 
        FROM violations 
        WHERE user_id = NEW.user_id
    )
    WHERE id = NEW.user_id;
END$$
DELIMITER ;

-- Trigger: Sync profile picture to users table
DELIMITER $$
CREATE TRIGGER after_profile_update
AFTER UPDATE ON student_profiles
FOR EACH ROW
BEGIN
    IF NEW.profile_picture != OLD.profile_picture OR 
       NEW.profile_picture_uploaded_at != OLD.profile_picture_uploaded_at THEN
        UPDATE users 
        SET profile_picture = NEW.profile_picture,
            profile_picture_uploaded_at = NEW.profile_picture_uploaded_at
        WHERE id = NEW.user_id;
    END IF;
END$$
DELIMITER ;

-- ============================================
-- DEFAULT DATA
-- ============================================

-- Create default admin account
INSERT INTO users (student_id, name, email, password, role, status) VALUES
('ADMIN001', 'System Admin', 'admin@pcu.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'Active');
-- Default password for admin is 'password' - change this immediately after first login

-- ============================================
-- VIEWS FOR BACKWARD COMPATIBILITY (Optional)
-- ============================================
-- These views can be used if you want to query normalized data
-- Current code uses denormalized columns in users table directly

-- View: Complete student information
CREATE OR REPLACE VIEW v_students_complete AS
SELECT 
    u.id,
    u.student_id,
    u.name,
    u.email,
    u.role,
    u.status,
    u.created_at,
    u.last_login,
    u.profile_picture,
    u.profile_picture_uploaded_at,
    u.rfid_uid,
    u.rfid_registered_at,
    u.violation_count,
    sp.bio,
    sp.phone,
    sp.emergency_contact,
    sp.emergency_phone,
    (SELECT COUNT(*) FROM violations v WHERE v.user_id = u.id) as total_violations,
    (SELECT MAX(scanned_at) FROM violations v WHERE v.user_id = u.id) as last_violation_date
FROM users u
LEFT JOIN student_profiles sp ON u.id = sp.user_id
WHERE u.role = 'Student';

-- View: Active RFID cards
CREATE OR REPLACE VIEW v_active_rfid_cards AS
SELECT 
    u.id as user_id,
    u.student_id,
    u.name,
    u.email,
    rc.rfid_uid,
    rc.registered_at,
    rc.registered_by,
    u.violation_count
FROM users u
INNER JOIN rfid_cards rc ON u.id = rc.user_id
WHERE rc.is_active = 1;

-- ============================================
-- INDEXES FOR OPTIMIZATION
-- ============================================
-- Additional composite indexes for common query patterns

-- Index for security gate queries (scan by RFID)
CREATE INDEX idx_users_rfid_lookup ON users(rfid_uid, role, status);

-- Index for admin dashboard (students with violations)
CREATE INDEX idx_users_violations ON users(role, violation_count, status);

-- Index for profile queries
CREATE INDEX idx_users_profile ON users(id, email, profile_picture);

-- ============================================
-- DATABASE CONSTRAINTS & RULES
-- ============================================

-- Ensure data integrity
ALTER TABLE users 
    ADD CONSTRAINT chk_failed_attempts CHECK (failed_attempts >= 0),
    ADD CONSTRAINT chk_violation_count CHECK (violation_count >= 0);

-- ============================================
-- NORMALIZATION NOTES
-- ============================================
-- This schema achieves normalization while maintaining backward compatibility:
--
-- 1. FIRST NORMAL FORM (1NF): ✓
--    - All tables have atomic values
--    - No repeating groups
--    - Primary keys defined for all tables
--
-- 2. SECOND NORMAL FORM (2NF): ✓
--    - All non-key attributes fully dependent on primary key
--    - RFID data separated to rfid_cards table
--    - Profile data separated to student_profiles table
--    - Violations separated to violations table
--
-- 3. THIRD NORMAL FORM (3NF): ✓
--    - No transitive dependencies
--    - Each table represents one entity
--    - Foreign keys properly defined
--
-- BACKWARD COMPATIBILITY STRATEGY:
-- - Denormalized columns (rfid_uid, profile_picture, violation_count) 
--   remain in users table
-- - Triggers automatically sync normalized tables → denormalized columns
-- - Existing queries continue to work without modification
-- - Future queries can use normalized tables or views
--
-- MIGRATION PATH:
-- - Phase 1: Current state (denormalized columns in users)
-- - Phase 2: Triggers keep both normalized & denormalized in sync
-- - Phase 3: Gradually migrate queries to use normalized tables
-- - Phase 4: Remove denormalized columns once all code updated
-- ============================================
