<?php

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start the session and include database connection
require_once __DIR__ . '/../db.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit();
}

// Auto-fix: Set admin_id if not already set (for existing sessions)
if (!isset($_SESSION['admin_id'])) {
    try {
        $pdo = pdo();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'Admin' LIMIT 1");
        $stmt->execute();
        $admin = $stmt->fetch(\PDO::FETCH_ASSOC);
        $_SESSION['admin_id'] = $admin['id'] ?? 1;
    } catch (\PDOException $e) {
        error_log("Auto-fix admin_id error: " . $e->getMessage());
        $_SESSION['admin_id'] = 1; // Fallback
    }
}

$page_title = 'Student Management';

// Auto-setup: Create audit_logs table if it doesn't exist
try {
    $pdo = pdo();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            admin_id INT NOT NULL,
            admin_name VARCHAR(255) NOT NULL,
            action_type VARCHAR(100) NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_id INT NULL,
            target_name VARCHAR(255) NULL,
            description TEXT NOT NULL,
            details JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_id (admin_id),
            INDEX idx_action_type (action_type),
            INDEX idx_target_type (target_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (\PDOException $e) {
    error_log('Audit table setup error: ' . $e->getMessage());
}

// Get all students
try {
    $pdo = pdo();
    
    // Auto-setup: Check if course column exists, if not add it (must run before SELECT queries)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS 
                           WHERE TABLE_SCHEMA = DATABASE() 
                           AND TABLE_NAME = 'users' 
                           AND COLUMN_NAME = 'course'");
    $stmt->execute();
    $courseExists = $stmt->fetchColumn() > 0;
    
    if (!$courseExists) {
        $pdo->exec("ALTER TABLE users ADD COLUMN course VARCHAR(255) NULL DEFAULT NULL AFTER email");
        error_log("[PCU RFID] Added course column to users table");
    }
    
    // Get all students WITHOUT registered RFID cards (for Student Management panel)
    // Only show Active students (exclude Pending students awaiting verification)
    $query = '
        SELECT id, student_id, name, email, course, status, role, rfid_uid, rfid_registered_at, profile_picture
        FROM users 
        WHERE role = "Student" AND rfid_uid IS NULL AND status = "Active"
        ORDER BY created_at DESC
    ';
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $students = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    // Get ALL students including those with RFID (for Registered Cards panel)
    // Only show Active students (exclude Pending students awaiting verification)
    $queryAll = '
        SELECT id, student_id, name, email, course, status, role, rfid_uid, rfid_registered_at, profile_picture, face_registered, face_registered_at
        FROM users 
        WHERE role = "Student" AND status = "Active"
        ORDER BY created_at DESC
    ';
    $stmtAll = $pdo->prepare($queryAll);
    $stmtAll->execute();
    $allStudents = $stmtAll->fetchAll(\PDO::FETCH_ASSOC);
    
    // Get registered cards count (only Active students)
    $stmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "Student" AND rfid_uid IS NOT NULL AND status = "Active"');
    $registeredCount = $stmt->fetchColumn();
    
    // Auto-setup: Check if google_id column exists, if not add it
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS 
                           WHERE TABLE_SCHEMA = DATABASE() 
                           AND TABLE_NAME = 'users' 
                           AND COLUMN_NAME = 'google_id'");
    $stmt->execute();
    $googleIdExists = $stmt->fetchColumn() > 0;
    
    if (!$googleIdExists) {
        $pdo->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL UNIQUE AFTER email");
        error_log("[PCU RFID] Added google_id column to users table");
    }
    
    // Auto-setup: Check if violation_count column exists, if not add it
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS 
                           WHERE TABLE_SCHEMA = DATABASE() 
                           AND TABLE_NAME = 'users' 
                           AND COLUMN_NAME = 'violation_count'");
    $stmt->execute();
    $columnExists = $stmt->fetchColumn() > 0;
    
    if (!$columnExists) {
        $pdo->exec("ALTER TABLE users ADD COLUMN violation_count INT NOT NULL DEFAULT 0");
    }
    
    // Auto-setup: Create violations table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS violations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            rfid_uid VARCHAR(50) NOT NULL,
            scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_scanned_at (scanned_at)
        )
    ");
    
    // Auto-setup: Create rfid_cards table if it doesn't exist (for lost/found tracking)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rfid_cards (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            rfid_uid VARCHAR(50) NOT NULL,
            registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_lost TINYINT(1) DEFAULT 0,
            lost_at TIMESTAMP NULL,
            lost_reason TEXT NULL,
            lost_reported_by INT NULL,
            found_at TIMESTAMP NULL,
            found_by INT NULL,
            status ENUM('active', 'inactive', 'lost', 'replaced') DEFAULT 'active',
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user (user_id),
            INDEX idx_rfid_uid (rfid_uid),
            INDEX idx_is_lost (is_lost)
        )
    ");
    
    // Auto-setup: Create rfid_status_history table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rfid_status_history (
            id INT PRIMARY KEY AUTO_INCREMENT,
            rfid_card_id INT NOT NULL,
            user_id INT NOT NULL,
            status_change VARCHAR(50) NOT NULL,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            changed_by INT NULL,
            reason TEXT NULL,
            notes TEXT NULL,
            ip_address VARCHAR(45) NULL,
            INDEX idx_rfid_card (rfid_card_id),
            INDEX idx_user (user_id)
        )
    ");
    
    // Auto-setup: Face recognition tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS face_descriptors (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            descriptor_data TEXT NOT NULL,
            descriptor_iv VARCHAR(48) NOT NULL,
            descriptor_tag VARCHAR(48) NOT NULL,
            label VARCHAR(100) DEFAULT NULL,
            quality_score FLOAT DEFAULT NULL,
            registered_by INT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS face_entry_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            confidence_score FLOAT NOT NULL,
            match_threshold FLOAT NOT NULL,
            gate_location VARCHAR(100) DEFAULT NULL,
            security_guard_id INT DEFAULT NULL,
            entry_type ENUM('face_match', 'face_violation', 'face_denied') NOT NULL DEFAULT 'face_match',
            snapshot_path VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at),
            INDEX idx_entry_type (entry_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS face_registration_log (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            action ENUM('registered', 'deactivated', 'reactivated', 'deleted') NOT NULL,
            descriptor_count INT DEFAULT 0,
            performed_by INT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Auto-setup: Add face_registered columns to users table
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS 
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'face_registered'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN face_registered TINYINT(1) NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE users ADD COLUMN face_registered_at TIMESTAMP NULL DEFAULT NULL");
        }
    } catch (\PDOException $e) {
        error_log("Face columns auto-setup: " . $e->getMessage());
    }
    
    // Auto-populate rfid_cards table with existing RFID registrations
    // Use a safer approach to avoid trigger conflicts
    try {
        // First, get all users who need entries in rfid_cards
        $stmt = $pdo->query("
            SELECT u.id, u.rfid_uid, u.rfid_registered_at
            FROM users u
            LEFT JOIN rfid_cards rc ON u.id = rc.user_id
            WHERE u.role = 'Student' 
            AND u.rfid_uid IS NOT NULL 
            AND rc.user_id IS NULL
        ");
        $missingCards = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Insert each missing entry individually to avoid trigger conflicts
        if (!empty($missingCards)) {
            $insertStmt = $pdo->prepare("
                INSERT INTO rfid_cards (user_id, rfid_uid, registered_at, status)
                VALUES (?, ?, ?, 'active')
            ");
            
            foreach ($missingCards as $card) {
                try {
                    $insertStmt->execute([
                        $card['id'],
                        $card['rfid_uid'],
                        $card['rfid_registered_at'] ?? date('Y-m-d H:i:s')
                    ]);
                } catch (\PDOException $e) {
                    // Skip duplicates or conflicts
                    error_log("Skipping rfid_cards insert for user {$card['id']}: " . $e->getMessage());
                }
            }
        }
    } catch (\PDOException $e) {
        // Log but don't fail - table population is optional
        error_log("Failed to auto-populate rfid_cards: " . $e->getMessage());
    }
    
    // Get students who reached maximum violations (3 or more)
    $maxViolationLimit = 3;
    $stmt = $pdo->prepare('SELECT id, student_id, name, email, rfid_uid, violation_count 
                           FROM users 
                           WHERE role = "Student" AND violation_count >= ? 
                           ORDER BY violation_count DESC, name ASC');
    $stmt->execute([$maxViolationLimit]);
    $violationAlerts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $violationAlertCount = count($violationAlerts);
    
    // Get violation analytics
    $dailyViolations = 0;
    $weeklyViolations = 0;
    $monthlyViolations = 0;
    $yearlyViolations = 0;
    
    // Check if violations table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'violations'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM violations WHERE DATE(scanned_at) = CURDATE()");
        $dailyViolations = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM violations WHERE YEARWEEK(scanned_at, 1) = YEARWEEK(CURDATE(), 1)");
        $weeklyViolations = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM violations WHERE MONTH(scanned_at) = MONTH(CURDATE()) AND YEAR(scanned_at) = YEAR(CURDATE())");
        $monthlyViolations = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM violations WHERE YEAR(scanned_at) = YEAR(CURDATE())");
        $yearlyViolations = $stmt->fetchColumn();
    }
    
    // Get pending verification students (using status = 'Pending')
    $stmt = $pdo->query('
        SELECT id, student_id, name, email, created_at, profile_picture 
        FROM users 
        WHERE role = "Student" AND status = "Pending"
        ORDER BY created_at DESC
    ');
    $pendingStudents = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $pendingCount = count($pendingStudents);
    
} catch (\PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    $error = 'Database error: ' . $e->getMessage();
} catch (\Exception $e) {
    error_log('Error fetching students: ' . $e->getMessage());
    $error = 'Failed to load student data: ' . $e->getMessage();
}

// Get audit logs (only for audit section)
$auditLogs = [];
if (($_GET['section'] ?? 'students') === 'audit') {
    try {
        $auditStmt = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 100");
        $auditLogs = $auditStmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        error_log('Audit logs fetch error: ' . $e->getMessage());
        $auditLogs = [];
    }
}

// Determine active section
$activeSection = $_GET['section'] ?? 'students';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PCU RFID Admin | <?php echo $page_title; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <!-- Dropzone.js for image upload -->
    <link rel="stylesheet" href="https://unpkg.com/dropzone@5/dist/min/dropzone.min.css" type="text/css" />
    <script src="https://unpkg.com/dropzone@5/dist/min/dropzone.min.js"></script>
    <script>Dropzone.autoDiscover = false;</script>
    <script src="../assets/js/digital-id-card.js?v=11"></script>
    <style type="text/tailwindcss">
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .btn-hover {
            transition: all 0.3s ease;
        }
        .btn-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 86, 179, 0.1), 0 2px 4px -1px rgba(0, 86, 179, 0.06);
        }
        
        /* Desktop sidebar - always visible */
        @media (min-width: 769px) {
            #sidebarToggle {
                display: none !important;
            }
            aside {
                transform: translateX(0) !important;
            }
        }
        
        /* Mobile sidebar toggle styles */
        @media (max-width: 768px) {
            aside {
                transition: transform 0.3s ease-in-out;
                z-index: 1000;
            }
            aside.sidebar-hidden {
                transform: translateX(-100%);
            }
            .main-content {
                transition: margin-left 0.3s ease-in-out;
                margin-left: 0 !important;
                padding-bottom: 2rem !important;
                padding-top: 1rem !important;
            }
            .toggle-btn {
                transition: all 0.3s ease;
                position: fixed !important;
                bottom: 1.5rem !important;
                right: 1.5rem !important;
                top: auto !important;
                left: auto !important;
                z-index: 1001 !important;
                box-shadow: 0 4px 12px rgba(0, 86, 179, 0.3) !important;
                width: 3.5rem !important;
                height: 3.5rem !important;
                min-width: 3.5rem !important;
                min-height: 3.5rem !important;
                border-radius: 50% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0 !important;
            }
            .toggle-btn:hover {
                background-color: #0056b3;
            }
            
            /* Overlay when sidebar is open on mobile */
            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 999;
                transition: opacity 0.3s ease-in-out;
            }
            .sidebar-overlay.active {
                display: block;
            }
        }
    </style>
</head>
<body class="bg-slate-50">

<!-- Main Layout with Sidebar -->
<div class="flex min-h-screen bg-slate-50">
    <!-- Overlay for mobile when sidebar is open -->
    <div id="sidebarOverlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>
    
    <!-- Sidebar Toggle Button (Mobile Only) -->
    <button 
        id="sidebarToggle" 
        class="toggle-btn fixed top-4 left-4 z-50 bg-[#0056b3] text-white shadow-lg hover:shadow-xl"
        onclick="toggleSidebar()"
        title="Toggle Menu"
    >
        <svg id="toggleIcon" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
    </button>

    <!-- Sidebar -->
    <aside id="sidebar" class="w-64 bg-white shadow-lg fixed top-0 left-0 h-screen overflow-y-auto z-40 sidebar-hidden">
        <div class="p-6">
            <a href="?section=students" class="flex items-center gap-3 mb-8 hover:opacity-80 transition-opacity">
                <img src="../pcu-logo.png" alt="PCU Logo" class="w-10 h-10">
                <div>
                    <h2 class="font-semibold text-slate-800">Admin Panel</h2>
                    <p class="text-xs text-slate-500">GateWatch</p>
                </div>
            </a>

            <!-- Navigation -->
            <nav class="space-y-2">
                <a href="?section=verify" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $activeSection === 'verify' ? 'bg-blue-50 text-[#0056b3]' : 'text-slate-600 hover:bg-slate-50'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div class="flex-1 flex items-center justify-between">
                        <span class="font-medium">Verify Students</span>
                        <?php if ($pendingCount > 0): ?>
                        <span class="bg-amber-500 text-white text-xs px-2 py-1 rounded-full animate-pulse"><?php echo $pendingCount; ?></span>
                        <?php endif; ?>
                    </div>
                </a>
                
                <a href="?section=students" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $activeSection === 'students' ? 'bg-blue-50 text-[#0056b3]' : 'text-slate-600 hover:bg-slate-50'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    <span class="font-medium">All Students</span>
                </a>

                <a href="?section=registered" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $activeSection === 'registered' ? 'bg-blue-50 text-[#0056b3]' : 'text-slate-600 hover:bg-slate-50'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                    </svg>
                    <div class="flex-1 flex items-center justify-between">
                        <span class="font-medium">Registered Cards</span>
                        <span class="bg-green-100 text-green-700 text-xs px-2 py-1 rounded-full"><?php echo $registeredCount; ?></span>
                    </div>
                </a>

                <a href="?section=analytics" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $activeSection === 'analytics' ? 'bg-blue-50 text-[#0056b3]' : 'text-slate-600 hover:bg-slate-50'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <span class="font-medium">Analytics</span>
                </a>

                <a href="?section=notifications" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $activeSection === 'notifications' ? 'bg-blue-50 text-[#0056b3]' : 'text-slate-600 hover:bg-slate-50'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                    <div class="flex-1 flex items-center justify-between">
                        <span class="font-medium">Notifications</span>
                        <?php if ($violationAlertCount > 0): ?>
                        <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full animate-pulse"><?php echo $violationAlertCount; ?></span>
                        <?php endif; ?>
                    </div>
                </a>

                <a href="?section=audit" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $activeSection === 'audit' ? 'bg-blue-50 text-[#0056b3]' : 'text-slate-600 hover:bg-slate-50'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span class="font-medium">Audit Log</span>
                </a>

                <a href="?section=rfid_checker" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $activeSection === 'rfid_checker' ? 'bg-blue-50 text-[#0056b3]' : 'text-slate-600 hover:bg-slate-50'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <span class="font-medium">RFID Checker</span>
                </a>

                <?php if (filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)): ?>
                <?php
                // Count students eligible for face enrollment (have RFID but no face registered)
                $faceEnrollCount = 0;
                foreach ($allStudents as $s) {
                    if (!empty($s['rfid_uid']) && empty($s['face_registered'])) $faceEnrollCount++;
                }
                ?>
                <a href="?section=face_enroll" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $activeSection === 'face_enroll' ? 'bg-blue-50 text-[#0056b3]' : 'text-slate-600 hover:bg-slate-50'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                    </svg>
                    <span class="font-medium">Face Enroll</span>
                    <?php if ($faceEnrollCount > 0): ?>
                        <span class="ml-auto bg-[#0056b3] text-white text-xs w-6 h-6 rounded-full flex items-center justify-center font-bold"><?php echo $faceEnrollCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="?section=face" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $activeSection === 'face' ? 'bg-blue-50 text-[#0056b3]' : 'text-slate-600 hover:bg-slate-50'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    <span class="font-medium">Face Management</span>
                </a>
                <?php endif; ?>
            </nav>

            <!-- Gate Monitor Link -->
            <div class="mt-4 pt-4 border-t border-slate-200">
                <a href="../security/gate_monitor.php" target="_blank" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors bg-gradient-to-r from-green-50 to-emerald-50 text-green-700 hover:from-green-100 hover:to-emerald-100 border border-green-200">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <div class="flex-1">
                        <span class="font-medium">Gate Monitor</span>
                        <p class="text-xs text-green-600">Security Access</p>
                    </div>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                </a>
            </div>

            <!-- Logout Button -->
            <div class="mt-8 pt-6 border-t border-slate-200">
                <a href="logout.php" class="flex items-center gap-3 px-4 py-3 rounded-lg text-red-600 hover:bg-red-50 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    <span class="font-medium">Logout</span>
                </a>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main id="mainContent" class="main-content flex-1 ml-64 p-8">
        <?php if (isset($error)): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-lg mb-6">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($activeSection === 'verify'): ?>
            <!-- Verify Students Section -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Verify Student Accounts</h1>
                <p class="text-slate-600 mt-1">Review and approve student registration requests</p>
            </div>

            <?php if (empty($pendingStudents)): ?>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <div class="p-12 text-center">
                        <svg class="w-16 h-16 mx-auto text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <h3 class="text-lg font-semibold text-slate-800 mb-2">All Caught Up!</h3>
                        <p class="text-slate-600">No pending student verifications at this time.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid gap-6">
                    <?php foreach ($pendingStudents as $student): ?>
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden fade-in">
                            <div class="p-6">
                                <div class="flex items-start gap-6">
                                    <!-- Profile Picture or Avatar -->
                                    <div class="flex-shrink-0">
                                        <?php if (!empty($student['profile_picture'])): ?>
                                            <img src="../assets/profiles/<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                                 alt="Profile" 
                                                 class="w-20 h-20 rounded-full object-cover border-4 border-slate-100">
                                        <?php else: ?>
                                            <?php
                                            $firstLetter = strtoupper(substr($student['name'], 0, 1));
                                            $colors = ['bg-blue-500', 'bg-green-500', 'bg-yellow-500', 'bg-red-500', 'bg-purple-500', 'bg-pink-500'];
                                            $colorIndex = ord($firstLetter) % count($colors);
                                            $bgColor = $colors[$colorIndex];
                                            ?>
                                            <div class="w-20 h-20 rounded-full <?php echo $bgColor; ?> flex items-center justify-center text-white text-2xl font-bold border-4 border-slate-100">
                                                <?php echo $firstLetter; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Student Info -->
                                    <div class="flex-1">
                                        <div class="flex items-start justify-between mb-4">
                                            <div>
                                                <h3 class="text-xl font-semibold text-slate-800 mb-1">
                                                    <?php echo htmlspecialchars($student['name']); ?>
                                                </h3>
                                                <div class="space-y-1">
                                                    <p class="text-sm text-slate-600 flex items-center gap-2">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                                                        </svg>
                                                        <strong>Student ID:</strong> 
                                                        <code class="bg-slate-100 px-2 py-0.5 rounded text-slate-800"><?php echo htmlspecialchars($student['student_id']); ?></code>
                                                    </p>
                                                    <p class="text-sm text-slate-600 flex items-center gap-2">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                                        </svg>
                                                        <strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?>
                                                    </p>
                                                    <p class="text-sm text-slate-600 flex items-center gap-2">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                        </svg>
                                                        <strong>Registered:</strong> <?php echo date('M d, Y • g:i A', strtotime($student['created_at'])); ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <span class="bg-amber-100 text-amber-800 text-xs font-semibold px-3 py-1 rounded-full">
                                                Pending Verification
                                            </span>
                                        </div>
                                        
                                        <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded-r-lg mb-4">
                                            <p class="text-sm text-amber-800">
                                                <strong>Action Required:</strong> Please verify this student's credentials against enrolled student records before approving their account.
                                            </p>
                                        </div>
                                        
                                        <!-- Action Buttons -->
                                        <div class="flex gap-3 mt-4">
                                            <button 
                                                onclick="approveStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['name'], ENT_QUOTES); ?>')"
                                                class="flex-1 px-6 py-3 bg-green-600 text-white rounded-lg font-semibold transition-all duration-150
                                                       hover:bg-green-700 hover:shadow-lg active:scale-[0.98]
                                                       flex items-center justify-center gap-2">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                </svg>
                                                Approve Account
                                            </button>
                                            <button 
                                                onclick="denyStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['name'], ENT_QUOTES); ?>')"
                                                class="flex-1 px-6 py-3 bg-red-600 text-white rounded-lg font-semibold transition-all duration-150
                                                       hover:bg-red-700 hover:shadow-lg active:scale-[0.98]
                                                       flex items-center justify-center gap-2">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                                Deny & Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($activeSection === 'students'): ?>
            <!-- All Students Section -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Student Management</h1>
                <p class="text-slate-600 mt-1">Manage student accounts and RFID registrations</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="p-6">
                    <?php if (empty($students)): ?>
                        <p class="text-slate-600 text-center py-8">No students found.</p>
                    <?php else: ?>
                        <div class="grid gap-4">
                        <?php foreach ($students as $student): ?>
                            <div class="bg-slate-50 rounded-lg p-4 fade-in flex items-center justify-between">
                                <div>
                                    <h3 class="font-medium text-slate-800">
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </h3>
                                    <p class="text-sm text-slate-600">
                                        Student ID: <?php echo htmlspecialchars($student['student_id']); ?>
                                    </p>
                                    <p class="text-sm text-slate-500">
                                        <?php echo htmlspecialchars($student['email']); ?>
                                    </p>
                                    <?php if ($student['rfid_uid']): ?>
                                    <p class="text-sm text-green-600 mt-1">
                                        <span class="inline-flex items-center">
                                            <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                            </svg>
                                            RFID Registered
                                        </span>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <div class="flex gap-2">
                                    <?php if (!$student['rfid_uid']): ?>
                                        <button 
                                            onclick="openCardRegistration('<?php echo htmlspecialchars($student['id']); ?>')"
                                            class="px-4 py-2 bg-[#0056b3] text-white rounded-lg btn-hover text-sm"
                                        >
                                            Register Card
                                        </button>
                                    <?php else: ?>
                                        <button 
                                            onclick="unregisterCard('<?php echo htmlspecialchars($student['id']); ?>')"
                                            class="px-4 py-2 bg-yellow-500 text-white rounded-lg btn-hover text-sm"
                                        >
                                            Unregister Card
                                        </button>
                                    <?php endif; ?>
                                    <button 
                                        onclick="confirmDelete('<?php echo htmlspecialchars($student['id']); ?>')"
                                        class="px-4 py-2 bg-red-500 text-white rounded-lg btn-hover text-sm"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($activeSection === 'registered'): ?>
            <!-- Registered Cards Section -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Registered RFID Cards</h1>
                <p class="text-slate-600 mt-1">View and manage students with registered cards</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="p-6">
                    <!-- Search Bar -->
                    <div class="mb-6">
                        <div class="relative">
                            <input 
                                type="text" 
                                id="registeredCardSearch" 
                                placeholder="Search by Student ID..." 
                                class="w-full px-4 py-3 pl-12 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
                                autocomplete="off"
                            >
                            <svg class="w-5 h-5 text-slate-400 absolute left-4 top-1/2 transform -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <p class="text-xs text-slate-500 mt-2" id="searchResultCount"></p>
                    </div>

                    <?php 
                    $registeredStudents = array_filter($allStudents, function($s) { return !empty($s['rfid_uid']); });
                    if (empty($registeredStudents)): 
                    ?>
                        <div class="text-center py-12">
                            <svg class="w-16 h-16 mx-auto text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"/>
                            </svg>
                            <p class="text-slate-600">No registered cards yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid gap-4">
                        <?php foreach ($registeredStudents as $student): 
                            // Get RFID card details including lost status
                            $cardInfo = null;
                            $isLost = false;
                            
                            try {
                                $cardStmt = $pdo->prepare("
                                    SELECT rc.id AS card_id, rc.is_lost, rc.lost_at, rc.lost_reason,
                                           '' AS reported_by_name
                                    FROM rfid_cards rc
                                    WHERE rc.user_id = ? AND rc.rfid_uid = ?
                                    ORDER BY rc.id DESC
                                    LIMIT 1
                                ");
                                $cardStmt->execute([$student['id'], $student['rfid_uid']]);
                                $cardInfo = $cardStmt->fetch();
                                
                                // If card not found in rfid_cards table, create it now
                                if (!$cardInfo && !empty($student['rfid_uid'])) {
                                    $insertStmt = $pdo->prepare("
                                        INSERT INTO rfid_cards (user_id, rfid_uid, registered_at, is_active)
                                        VALUES (?, ?, ?, 1)
                                    ");
                                    $insertStmt->execute([
                                        $student['id'],
                                        $student['rfid_uid'],
                                        $student['rfid_registered_at'] ?? date('Y-m-d H:i:s')
                                    ]);
                                    
                                    // Fetch the newly created card
                                    $cardStmt->execute([$student['id'], $student['rfid_uid']]);
                                    $cardInfo = $cardStmt->fetch();
                                }

                                // Fallback: if UID-specific row not found, use latest card row for this student
                                if (!$cardInfo) {
                                    $fallbackStmt = $pdo->prepare("
                                        SELECT rc.id AS card_id,
                                               COALESCE(rc.is_lost, 0) AS is_lost,
                                               rc.lost_at,
                                               rc.lost_reason,
                                               '' AS reported_by_name
                                        FROM rfid_cards rc
                                        WHERE rc.user_id = ?
                                        ORDER BY rc.id DESC
                                        LIMIT 1
                                    ");
                                    $fallbackStmt->execute([$student['id']]);
                                    $cardInfo = $fallbackStmt->fetch();
                                }
                                
                                $isLost = $cardInfo && $cardInfo['is_lost'] == 1;
                            } catch (\PDOException $e) {
                                // If rfid_cards table doesn't exist or query fails, use basic info from users table
                                error_log("RFID card query error: " . $e->getMessage());

                                // Minimal fallback query for legacy/partial schemas
                                try {
                                    $fallbackStmt = $pdo->prepare("
                                        SELECT id AS card_id,
                                               0 AS is_lost,
                                               NULL AS lost_at,
                                               NULL AS lost_reason,
                                               '' AS reported_by_name
                                        FROM rfid_cards
                                        WHERE user_id = ?
                                        ORDER BY id DESC
                                        LIMIT 1
                                    ");
                                    $fallbackStmt->execute([$student['id']]);
                                    $cardInfo = $fallbackStmt->fetch();
                                } catch (\PDOException $fallbackErr) {
                                    error_log("RFID fallback query error: " . $fallbackErr->getMessage());
                                    $cardInfo = ['card_id' => 0, 'is_lost' => 0, 'lost_at' => null, 'lost_reason' => null, 'reported_by_name' => ''];
                                }

                                $isLost = false;
                            }
                        ?>
                            <div class="border <?php echo $isLost ? 'border-red-300 bg-red-50/50' : 'border-green-200 bg-green-50/50'; ?> rounded-lg p-4 fade-in registered-card-item" 
                                 data-student-id="<?php echo strtolower(htmlspecialchars($student['student_id'])); ?>" 
                                 data-student-name="<?php echo strtolower(htmlspecialchars($student['name'])); ?>">
                                <!-- Desktop: side-by-side layout, Mobile: centered layout -->
                                <div class="flex flex-col md:flex-row items-center md:items-stretch md:justify-between text-center md:text-left">
                                    <div class="w-full md:flex-1 flex flex-col">
                                        <div class="flex items-center justify-center md:justify-start gap-2 mb-2">
                                            <h3 class="font-semibold text-slate-800">
                                                <?php echo htmlspecialchars($student['name']); ?>
                                            </h3>
                                            <?php if ($isLost): ?>
                                                <span class="bg-red-100 text-red-700 text-xs px-2 py-1 rounded-full">🔴 LOST</span>
                                            <?php else: ?>
                                                <span class="bg-green-100 text-green-700 text-xs px-2 py-1 rounded-full">Active</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm text-slate-600 mb-1">
                                            Student ID: <?php echo htmlspecialchars($student['student_id']); ?>
                                        </p>
                                        <p class="text-sm text-slate-500 mb-2">
                                            <?php echo htmlspecialchars($student['email']); ?>
                                        </p>
                                        <div class="bg-white rounded-md p-3 mt-3 max-w-md mx-auto md:mx-0 flex-1 flex flex-col justify-between">
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-slate-600">RFID UID:</span>
                                                <code class="bg-slate-100 px-3 py-1 rounded font-mono text-slate-800"><?php echo htmlspecialchars($student['rfid_uid']); ?></code>
                                            </div>
                                            <div class="flex items-center justify-between text-sm mt-2">
                                                <span class="text-slate-600">Registered:</span>
                                                <span class="text-slate-700"><?php echo date('M d, Y h:i A', strtotime($student['rfid_registered_at'])); ?></span>
                                            </div>
                                            <?php if ($isLost): ?>
                                                <div class="mt-3 p-2 bg-red-100 border border-red-200 rounded-md">
                                                    <div class="flex items-center justify-between text-xs">
                                                        <span class="text-red-600 font-semibold">Lost Date:</span>
                                                        <span class="text-red-700"><?php echo date('M d, Y h:i A', strtotime($cardInfo['lost_at'])); ?></span>
                                                    </div>
                                                    <?php if (!empty($cardInfo['lost_reason'])): ?>
                                                    <div class="mt-1 text-xs text-red-600">
                                                        <strong>Reason:</strong> <?php echo htmlspecialchars($cardInfo['lost_reason']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($cardInfo['reported_by_name'])): ?>
                                                    <div class="mt-1 text-xs text-red-500">
                                                        <strong>Reported by:</strong> <?php echo htmlspecialchars($cardInfo['reported_by_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex flex-row md:flex-col gap-2 mt-4 md:mt-0 md:ml-4">
                                        <button 
                                            onclick="openEditStudentModal('<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['student_id']); ?>', '<?php echo htmlspecialchars($student['email']); ?>', '<?php echo htmlspecialchars($student['profile_picture'] ?? ''); ?>', '<?php echo htmlspecialchars($student['course'] ?? ''); ?>')"
                                            class="px-4 py-2 bg-indigo-500 text-white rounded-lg btn-hover text-sm whitespace-nowrap"
                                        >
                                            Edit
                                        </button>
                                        <button 
                                            onclick="viewCardDetails('<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['rfid_uid']); ?>', '<?php echo htmlspecialchars($student['rfid_registered_at']); ?>', '<?php echo htmlspecialchars($student['profile_picture'] ?? ''); ?>')"
                                            class="px-4 py-2 bg-blue-500 text-white rounded-lg btn-hover text-sm whitespace-nowrap"
                                        >
                                            View Details
                                        </button>
                                        <?php if ($isLost): ?>
                                            <button 
                                                onclick="toggleRfidLostStatus(<?php echo (int)($cardInfo['card_id'] ?? 0); ?>, '<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['rfid_uid']); ?>', '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['email']); ?>', false)"
                                                class="px-4 py-2 bg-green-600 text-white rounded-lg btn-hover text-sm whitespace-nowrap flex items-center gap-2"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                                Disable Mark Lost ID
                                            </button>
                                        <?php else: ?>
                                            <button 
                                                onclick="toggleRfidLostStatus(<?php echo (int)($cardInfo['card_id'] ?? 0); ?>, '<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['rfid_uid']); ?>', '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['email']); ?>', true)"
                                                class="px-4 py-2 bg-orange-600 text-white rounded-lg btn-hover text-sm whitespace-nowrap flex items-center gap-2"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                                </svg>
                                                Enable Mark Lost ID
                                            </button>
                                        <?php endif; ?>
                                        <button 
                                            onclick="unregisterCard('<?php echo htmlspecialchars($student['id']); ?>')"
                                            class="px-4 py-2 bg-yellow-500 text-white rounded-lg btn-hover text-sm whitespace-nowrap"
                                        >
                                            Unregister
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($activeSection === 'notifications'): ?>
            <!-- Notifications Section -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Violation Notifications</h1>
                <p class="text-slate-600 mt-1">Students who have reached the maximum violation limit</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="p-6">
                    <?php if (empty($violationAlerts)): ?>
                        <div class="text-center py-12">
                            <svg class="w-16 h-16 mx-auto text-green-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="text-slate-600 font-medium">No violation alerts at this time</p>
                            <p class="text-slate-500 text-sm mt-1">All students are within acceptable violation limits</p>
                        </div>
                    <?php else: ?>
                        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                <span class="text-red-800 font-semibold"><?php echo $violationAlertCount; ?> student<?php echo $violationAlertCount !== 1 ? 's' : ''; ?> reached maximum violation limit (<?php echo $maxViolationLimit; ?> strikes)</span>
                            </div>
                        </div>

                        <div class="grid gap-4">
                        <?php foreach ($violationAlerts as $student): ?>
                            <div class="border-2 border-red-300 bg-red-50/50 rounded-lg p-5 fade-in">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-3">
                                            <div class="bg-red-100 p-2 rounded-full">
                                                <svg class="w-6 h-6 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                                </svg>
                                            </div>
                                            <div>
                                                <h3 class="font-bold text-slate-800 text-lg">
                                                    <?php echo htmlspecialchars($student['name']); ?>
                                                </h3>
                                                <p class="text-sm text-slate-600">Student ID: <?php echo htmlspecialchars($student['student_id']); ?></p>
                                            </div>
                                            <span class="bg-red-500 text-white text-sm font-bold px-3 py-1 rounded-full">
                                                <?php echo $student['violation_count']; ?> Strikes
                                            </span>
                                        </div>
                                        
                                        <div class="bg-white rounded-lg p-4 border border-red-200">
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <p class="text-xs text-slate-500 mb-1">Email Address</p>
                                                    <p class="text-sm font-medium text-slate-700"><?php echo htmlspecialchars($student['email']); ?></p>
                                                </div>
                                                <div>
                                                    <p class="text-xs text-slate-500 mb-1">RFID Card UID</p>
                                                    <code class="text-sm bg-slate-100 px-2 py-1 rounded font-mono">
                                                        <?php echo $student['rfid_uid'] ? htmlspecialchars($student['rfid_uid']) : 'Not Registered'; ?>
                                                    </code>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex flex-col gap-2 ml-4">
                                        <button 
                                            onclick="viewViolationDetails('<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['student_id']); ?>', '<?php echo htmlspecialchars($student['email']); ?>', '<?php echo htmlspecialchars($student['rfid_uid'] ?? 'N/A'); ?>', '<?php echo $student['violation_count']; ?>')"
                                            class="px-4 py-2 bg-blue-500 text-white rounded-lg btn-hover text-sm whitespace-nowrap flex items-center gap-2"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                            View
                                        </button>
                                        <button 
                                            onclick="confirmClearViolation('<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['name']); ?>')"
                                            class="px-4 py-2 bg-green-500 text-white rounded-lg btn-hover text-sm whitespace-nowrap flex items-center gap-2"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            Clear Violation
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($activeSection === 'analytics'): ?>
            <!-- Analytics Section -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Violation Analytics</h1>
                <p class="text-slate-600 mt-1">Track student card scan violations over time</p>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <!-- Daily -->
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium opacity-90">Today</h3>
                        <svg class="w-8 h-8 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <p class="text-3xl font-bold mb-1"><?php echo number_format($dailyViolations); ?></p>
                    <p class="text-sm opacity-75">Violations</p>
                </div>

                <!-- Weekly -->
                <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium opacity-90">This Week</h3>
                        <svg class="w-8 h-8 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <p class="text-3xl font-bold mb-1"><?php echo number_format($weeklyViolations); ?></p>
                    <p class="text-sm opacity-75">Violations</p>
                </div>

                <!-- Monthly -->
                <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl shadow-lg p-6 text-white">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium opacity-90">This Month</h3>
                        <svg class="w-8 h-8 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <p class="text-3xl font-bold mb-1"><?php echo number_format($monthlyViolations); ?></p>
                    <p class="text-sm opacity-75">Violations</p>
                </div>

                <!-- Yearly -->
                <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium opacity-90">This Year</h3>
                        <svg class="w-8 h-8 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <p class="text-3xl font-bold mb-1"><?php echo number_format($yearlyViolations); ?></p>
                    <p class="text-sm opacity-75">Violations</p>
                </div>
            </div>

            <!-- Recent Violations Table -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="p-6">
                    <h2 class="text-lg font-semibold text-slate-800 mb-4">Recent Violations</h2>
                    <?php
                    try {
                        $tableCheck = $pdo->query("SHOW TABLES LIKE 'violations'")->fetch();
                        if ($tableCheck) {
                            $stmt = $pdo->query("
                                SELECT v.*, u.name, u.student_id, u.email 
                                FROM violations v
                                JOIN users u ON v.user_id = u.id
                                ORDER BY v.scanned_at DESC
                                LIMIT 20
                            ");
                            $recentViolations = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                            
                            if (!empty($recentViolations)):
                    ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-slate-200">
                                    <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Student</th>
                                    <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Student ID</th>
                                    <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">RFID UID</th>
                                    <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Scanned At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentViolations as $violation): ?>
                                <tr class="border-b border-slate-100 hover:bg-slate-50">
                                    <td class="py-3 px-4">
                                        <div>
                                            <p class="font-medium text-slate-800"><?php echo htmlspecialchars($violation['name']); ?></p>
                                            <p class="text-sm text-slate-500"><?php echo htmlspecialchars($violation['email']); ?></p>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-slate-600"><?php echo htmlspecialchars($violation['student_id']); ?></td>
                                    <td class="py-3 px-4">
                                        <code class="bg-slate-100 px-2 py-1 rounded text-sm"><?php echo htmlspecialchars($violation['rfid_uid']); ?></code>
                                    </td>
                                    <td class="py-3 px-4 text-slate-600 text-sm"><?php echo date('M d, Y h:i A', strtotime($violation['scanned_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php 
                            else:
                                echo '<p class="text-slate-600 text-center py-8">No violations recorded yet.</p>';
                            endif;
                        } else {
                            echo '<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-center">';
                            echo '<p class="text-yellow-800">Violations table not created yet. Violations will be tracked once students scan their cards.</p>';
                            echo '</div>';
                        }
                    } catch (\Exception $e) {
                        echo '<p class="text-red-600 text-center py-8">Error loading violations: ' . htmlspecialchars($e->getMessage()) . '</p>';
                    }
                    ?>
                </div>
            </div>

        <?php elseif ($activeSection === 'audit'): ?>
            <!-- Audit Log Section -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800">Audit Log</h1>
                    <p class="text-slate-600 mt-1">Track all administrative actions and changes</p>
                </div>
                <div class="flex items-center gap-3">
                    <span id="auditLiveIndicator" class="hidden items-center gap-2 px-3 py-1.5 bg-green-50 border border-green-200 rounded-full text-sm text-green-700">
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
                        </span>
                        Live
                    </span>
                    <button id="auditLiveToggle" onclick="toggleAuditLiveRefresh()" class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg hover:bg-green-100 hover:text-green-700 transition-colors font-medium text-sm border border-slate-300 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        <span id="auditLiveToggleText">Enable Live</span>
                    </button>
                </div>
            </div>

            <!-- Filter Options -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Action Type</label>
                        <select id="filterActionType" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Actions</option>
                            <option value="APPROVE_STUDENT">Approve Student</option>
                            <option value="DENY_STUDENT">Deny Student</option>
                            <option value="REGISTER_RFID">Register RFID</option>
                            <option value="UNREGISTER_RFID">Unregister RFID</option>
                            <option value="MARK_LOST">Mark Lost</option>
                            <option value="MARK_FOUND">Mark Found</option>
                            <option value="UPDATE_STUDENT">Update Student</option>
                            <option value="DELETE_STUDENT">Delete Student</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Date From</label>
                        <input type="date" id="filterDateFrom" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Date To</label>
                        <input type="date" id="filterDateTo" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div class="flex items-end">
                        <button onclick="applyAuditFilters()" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                            Apply Filters
                        </button>
                    </div>
                </div>
            </div>

            <!-- Audit Log Table -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Timestamp</th>
                                <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Admin</th>
                                <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Action</th>
                                <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Target</th>
                                <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Description</th>
                                <th class="text-center py-3 px-4 text-sm font-semibold text-slate-700">Details</th>
                            </tr>
                        </thead>
                        <tbody id="auditLogTableBody">
                            <?php
                            if (empty($auditLogs)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-12 text-slate-600">
                                        <svg class="w-16 h-16 mx-auto text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                        <p class="font-medium text-slate-700 mb-1">No audit logs yet</p>
                                        <p class="text-sm text-slate-500">Administrative actions will appear here</p>
                                    </td>
                                </tr>
                            <?php else:
                                foreach ($auditLogs as $log):
                                    // Color-code action types
                                    $actionColors = [
                                        'APPROVE_STUDENT' => 'bg-green-100 text-green-800',
                                        'DENY_STUDENT' => 'bg-red-100 text-red-800',
                                        'REGISTER_RFID' => 'bg-blue-100 text-blue-800',
                                        'UNREGISTER_RFID' => 'bg-yellow-100 text-yellow-800',
                                        'MARK_LOST' => 'bg-orange-100 text-orange-800',
                                        'MARK_FOUND' => 'bg-emerald-100 text-emerald-800',
                                        'UPDATE_STUDENT' => 'bg-indigo-100 text-indigo-800',
                                        'DELETE_STUDENT' => 'bg-red-100 text-red-800'
                                    ];
                                    $actionColor = $actionColors[$log['action_type']] ?? 'bg-slate-100 text-slate-800';
                            ?>
                                <tr class="border-b border-slate-100 hover:bg-slate-50 transition-colors">
                                    <td class="py-3 px-4 text-sm text-slate-600">
                                        <?php echo date('M d, Y', strtotime($log['created_at'])); ?><br>
                                        <span class="text-xs text-slate-500"><?php echo date('h:i A', strtotime($log['created_at'])); ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-sm font-medium text-slate-800">
                                        <?php echo htmlspecialchars($log['admin_name']); ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="<?php echo $actionColor; ?> text-xs font-semibold px-2 py-1 rounded-full">
                                            <?php echo str_replace('_', ' ', $log['action_type']); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-slate-700">
                                        <?php if ($log['target_name']): ?>
                                            <strong><?php echo htmlspecialchars($log['target_name']); ?></strong><br>
                                            <span class="text-xs text-slate-500"><?php echo ucfirst($log['target_type']); ?> ID: <?php echo $log['target_id']; ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-500">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-slate-600">
                                        <?php echo htmlspecialchars($log['description']); ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <?php if ($log['details']): ?>
                                            <button onclick='showAuditDetails(<?php echo htmlspecialchars(json_encode($log), ENT_QUOTES); ?>)' 
                                                    class="text-blue-600 hover:text-blue-800 text-sm font-medium transition-colors">
                                                View Details
                                            </button>
                                        <?php else: ?>
                                            <span class="text-slate-400 text-sm">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($activeSection === 'face_enroll'): ?>
            <!-- Face Enrollment Section -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Face Enrollment</h1>
                <p class="text-slate-600 mt-1">Enroll student faces for gate recognition. Only students with a registered RFID card are shown.</p>
            </div>

            <?php if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
                    <p class="text-yellow-800 font-medium">Face recognition is currently disabled.</p>
                    <p class="text-yellow-600 text-sm mt-1">Set FACE_RECOGNITION_ENABLED=true in your .env file.</p>
                </div>
            <?php else: ?>

            <?php
            // Get students with RFID registered but face NOT yet enrolled
            $enrollEligible = [];
            foreach ($allStudents as $s) {
                if (!empty($s['rfid_uid']) && empty($s['face_registered'])) {
                    $enrollEligible[] = $s;
                }
            }
            // Also get recently enrolled (last 7 days) for the "recently enrolled" section
            try {
                $recentEnrolled = $pdo->query("
                    SELECT u.id, u.name, u.student_id, u.email, u.profile_picture, u.face_registered_at,
                           (SELECT COUNT(*) FROM face_descriptors fd WHERE fd.user_id = u.id AND fd.is_active = 1) AS descriptor_count
                    FROM users u
                    WHERE u.role = 'Student' AND u.status = 'Active' AND u.face_registered = 1
                      AND u.face_registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ORDER BY u.face_registered_at DESC
                    LIMIT 10
                ")->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                $recentEnrolled = [];
            }
            ?>

            <!-- Stats -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-[#0056b3]">
                    <p class="text-sm text-slate-500">Pending Enrollment</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo count($enrollEligible); ?></p>
                    <p class="text-xs text-slate-400 mt-1">Students with RFID, no face</p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-green-500">
                    <p class="text-sm text-slate-500">Recently Enrolled</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo count($recentEnrolled); ?></p>
                    <p class="text-xs text-slate-400 mt-1">Last 7 days</p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-purple-500">
                    <p class="text-sm text-slate-500">Total RFID Registered</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo $registeredCount; ?></p>
                    <p class="text-xs text-slate-400 mt-1">Active students with cards</p>
                </div>
            </div>

            <!-- Pending Enrollment Table -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800">📷 Pending Face Enrollment</h3>
                        <p class="text-sm text-slate-600">Students who have RFID cards but no face registered yet</p>
                    </div>
                    <input type="text" id="enrollSearchInput" placeholder="Search by name or ID..." 
                           class="px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           onkeyup="filterEnrollStudents()">
                </div>

                <?php if (empty($enrollEligible)): ?>
                    <div class="text-center py-16 px-6">
                        <svg class="w-20 h-20 mx-auto text-green-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <h4 class="text-xl font-semibold text-slate-700 mb-2">All Caught Up!</h4>
                        <p class="text-slate-500">All students with RFID cards have been enrolled in face recognition.</p>
                        <p class="text-slate-400 text-sm mt-1">New students will appear here after their RFID card is registered.</p>
                    </div>
                <?php else: ?>
                    <div class="grid gap-4 p-6" id="enrollStudentGrid">
                        <?php foreach ($enrollEligible as $es): ?>
                        <div class="border border-slate-200 rounded-xl p-5 hover:border-[#0056b3] hover:shadow-md transition-all enroll-student-card"
                             data-search="<?php echo strtolower(e($es['name']) . ' ' . e($es['student_id']) . ' ' . e($es['email'])); ?>">
                            <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4">
                                <!-- Profile Picture -->
                                <div class="flex-shrink-0">
                                    <?php if (!empty($es['profile_picture'])): ?>
                                        <img src="../assets/images/profiles/<?php echo e($es['profile_picture']); ?>" 
                                             class="w-16 h-16 rounded-full object-cover border-2 border-slate-200">
                                    <?php else: ?>
                                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-bold text-xl">
                                            <?php echo strtoupper(substr($es['name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Student Info -->
                                <div class="flex-1 text-center sm:text-left">
                                    <h4 class="font-semibold text-slate-800 text-lg"><?php echo e($es['name']); ?></h4>
                                    <p class="text-sm text-slate-600">ID: <?php echo e($es['student_id']); ?></p>
                                    <p class="text-sm text-slate-500"><?php echo e($es['email']); ?></p>
                                    <div class="flex items-center gap-2 mt-2 justify-center sm:justify-start">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                            RFID: <?php echo e($es['rfid_uid']); ?>
                                        </span>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700">
                                            ⏳ Face Not Enrolled
                                        </span>
                                    </div>
                                </div>

                                <!-- Enroll Button -->
                                <div class="flex-shrink-0">
                                    <button onclick="openFaceEnrollModal(<?php echo (int)$es['id']; ?>, <?php echo e(json_encode($es['name'])); ?>, <?php echo e(json_encode($es['student_id'])); ?>)" 
                                            class="px-5 py-2.5 bg-[#0056b3] hover:bg-blue-700 text-white rounded-xl transition-colors font-medium text-sm shadow-sm hover:shadow-md flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        </svg>
                                        Enroll Face
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($recentEnrolled)): ?>
            <!-- Recently Enrolled -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-200">
                    <h3 class="text-lg font-semibold text-slate-800">✅ Recently Enrolled</h3>
                    <p class="text-sm text-slate-600">Faces enrolled in the last 7 days</p>
                </div>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($recentEnrolled as $re): ?>
                    <div class="px-6 py-4 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <?php if (!empty($re['profile_picture'])): ?>
                                <img src="../assets/images/profiles/<?php echo e($re['profile_picture']); ?>" class="w-10 h-10 rounded-full object-cover">
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center text-green-700 font-bold">
                                    <?php echo strtoupper(substr($re['name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <p class="font-medium text-slate-800"><?php echo e($re['name']); ?></p>
                                <p class="text-xs text-slate-500"><?php echo e($re['student_id']); ?> • <?php echo e($re['email']); ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                ✅ <?php echo (int)$re['descriptor_count']; ?> face(s)
                            </span>
                            <p class="text-xs text-slate-400 mt-1"><?php echo date('M d, Y h:i A', strtotime($re['face_registered_at'])); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>

        <?php elseif ($activeSection === 'face'): ?>
            <!-- Face Recognition Management Section -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Face Recognition Management</h1>
                <p class="text-slate-600 mt-1">Register and manage student face recognition data</p>
            </div>

            <?php if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
                    <p class="text-yellow-800 font-medium">Face recognition is currently disabled.</p>
                    <p class="text-yellow-600 text-sm mt-1">Set FACE_RECOGNITION_ENABLED=true in your .env file.</p>
                </div>
            <?php else: ?>

            <!-- Face Stats -->
            <?php
            try {
                $faceRegCount = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM face_descriptors WHERE is_active = 1")->fetchColumn();
                $faceTotalDesc = $pdo->query("SELECT COUNT(*) FROM face_descriptors WHERE is_active = 1")->fetchColumn();
                $faceEntriesToday = $pdo->query("SELECT COUNT(*) FROM face_entry_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
            } catch (\PDOException $e) {
                $faceRegCount = 0;
                $faceTotalDesc = 0;
                $faceEntriesToday = 0;
            }
            ?>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <p class="text-sm text-slate-500">Students with Face ID</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo $faceRegCount; ?></p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <p class="text-sm text-slate-500">Total Descriptors</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo $faceTotalDesc; ?></p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <p class="text-sm text-slate-500">Face Entries Today</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo $faceEntriesToday; ?></p>
                </div>
            </div>

            <!-- Student List for Face Registration -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800">Active Students</h3>
                        <p class="text-sm text-slate-600">Click "Register Face" to capture face data via webcam</p>
                    </div>
                    <input type="text" id="faceSearchInput" placeholder="Search students..." 
                           class="px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           onkeyup="filterFaceStudents()">
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full" id="faceStudentTable">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Student ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Face Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Descriptors</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <?php
                            // Get all active students with face registration status
                            try {
                                $faceStudents = $pdo->query("
                                    SELECT u.id, u.student_id, u.name, u.email, u.profile_picture, u.face_registered,
                                           (SELECT COUNT(*) FROM face_descriptors fd WHERE fd.user_id = u.id AND fd.is_active = 1) as descriptor_count
                                    FROM users u
                                    WHERE u.role = 'Student' AND u.status = 'Active'
                                    ORDER BY u.face_registered ASC, u.name ASC
                                ")->fetchAll(\PDO::FETCH_ASSOC);
                            } catch (\PDOException $e) {
                                $faceStudents = [];
                            }
                            
                            if (empty($faceStudents)): ?>
                                <tr><td colspan="5" class="px-6 py-8 text-center text-slate-400">No active students found.</td></tr>
                            <?php else:
                                foreach ($faceStudents as $fs): ?>
                                <tr class="hover:bg-slate-50 face-student-row">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <?php if (!empty($fs['profile_picture'])): ?>
                                                <img src="../assets/images/profiles/<?php echo e($fs['profile_picture']); ?>" class="w-10 h-10 rounded-full object-cover">
                                            <?php else: ?>
                                                <div class="w-10 h-10 rounded-full bg-slate-200 flex items-center justify-center text-slate-600 font-bold">
                                                    <?php echo strtoupper(substr($fs['name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <p class="font-medium text-slate-800"><?php echo e($fs['name']); ?></p>
                                                <p class="text-xs text-slate-500"><?php echo e($fs['email']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-600"><?php echo e($fs['student_id']); ?></td>
                                    <td class="px-6 py-4">
                                        <?php if ($fs['face_registered']): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                                Registered
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-500">
                                                Not Registered
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-600"><?php echo (int)$fs['descriptor_count']; ?> / <?php echo env('FACE_MAX_DESCRIPTORS_PER_STUDENT', '5'); ?></td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <button onclick="openFaceRegModal(<?php echo $fs['id']; ?>, <?php echo e(json_encode($fs['name'])); ?>, <?php echo e(json_encode($fs['student_id'])); ?>)" 
                                                    class="px-3 py-1.5 bg-[#0056b3] hover:bg-blue-700 text-white text-xs rounded-lg transition-colors font-medium">
                                                📷 Register Face
                                            </button>
                                            <?php if ($fs['face_registered']): ?>
                                            <button onclick="deleteFaceData(<?php echo $fs['id']; ?>, <?php echo e(json_encode($fs['name'])); ?>)" 
                                                    class="px-3 py-1.5 bg-red-500 hover:bg-red-600 text-white text-xs rounded-lg transition-colors font-medium">
                                                🗑 Delete
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif ($activeSection === 'rfid_checker'): ?>
            <!-- RFID ID Checker Section -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-800">RFID ID Checker</h1>
                <p class="text-slate-600 mt-1">Scan or enter an RFID UID to look up card status, student info, violations, and last scan</p>
            </div>

            <div class="max-w-3xl">
                <!-- Scan Input Area -->
                <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                    <div class="flex flex-col sm:flex-row gap-4">
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-slate-700 mb-2">RFID Card UID</label>
                            <input type="text" id="rfidCheckerInput" placeholder="Tap RFID card or type UID here..." 
                                   class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-lg font-mono tracking-wider"
                                   autocomplete="off" autofocus>
                        </div>
                        <div class="flex items-end">
                            <button onclick="lookupRfidCard()" class="px-6 py-3 bg-[#0056b3] text-white rounded-lg hover:bg-blue-700 transition-colors font-medium flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                Check
                            </button>
                        </div>
                    </div>
                    <p class="text-xs text-slate-400 mt-2">Tip: Place cursor in the field and tap the RFID card on the reader. The UID will auto-populate.</p>
                </div>

                <!-- Result Area -->
                <div id="rfidCheckerResult"></div>
            </div>

        <?php endif; ?>
    </main>
</div>

<!-- Card Registration Modal -->
<div id="cardModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4 fade-in">
        <h3 class="text-xl font-semibold text-slate-800 mb-4">Register RFID Card</h3>
        <p class="text-slate-600 mb-6">Please tap the RFID card on the reader to register it.</p>
        <div class="text-center" id="rfidStatus">
            <div class="animate-pulse inline-flex items-center">
                <div class="h-3 w-3 bg-blue-500 rounded-full mr-2"></div>
                <span class="text-slate-600">Waiting for card...</span>
            </div>
        </div>
        <button onclick="closeCardModal()" class="mt-6 px-4 py-2 text-slate-600 hover:text-slate-800">
            Cancel
        </button>
    </div>
</div>

<!-- Card Details Modal -->
<div id="cardDetailsModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-6 max-w-lg w-full mx-4 fade-in">
        <h3 class="text-xl font-semibold text-slate-800 mb-4">RFID Card Details</h3>
        <div id="cardDetailsContent"></div>
        <button onclick="closeCardDetailsModal()" class="mt-6 px-4 py-2 bg-slate-200 text-slate-700 rounded-lg hover:bg-slate-300">
            Close
        </button>
    </div>
</div>

<!-- Violation Details Modal -->
<div id="violationDetailsModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-6 max-w-2xl w-full mx-4 fade-in">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-semibold text-slate-800">Student Violation Details</h3>
            <button onclick="closeViolationDetailsModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="violationDetailsContent"></div>
    </div>
</div>

<!-- Edit Student Modal -->
<div id="editStudentModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-8 max-w-5xl w-full mx-4 fade-in max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-semibold text-slate-800">Edit Student Information</h3>
            <button onclick="closeEditStudentModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Left Column: Edit Form -->
            <div id="editStudentForm">
                <input type="hidden" id="editStudentUserId" />
                
                <!-- Student Information -->
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Full Name</label>
                        <input type="text" id="editStudentName" oninput="updateDigitalIdPreview()"
                               class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Student ID</label>
                        <input type="text" id="editStudentId" inputmode="numeric" pattern="[0-9]*" oninput="enforceNumericStudentId(this); updateDigitalIdPreview()"
                               class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none">
                        <p class="text-xs text-slate-500 mt-1">For temp IDs (e.g., TEMP-1702425600), enter the real student ID here</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Course / Program</label>
                        <select id="editStudentCourse" onchange="updateDigitalIdPreview()"
                                class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none">
                            <option value="">-- Select Course --</option>
                            <option value="BS Computer Science">BS Computer Science</option>
                            <option value="BS Information Technology">BS Information Technology</option>
                            <option value="BS Information Systems">BS Information Systems</option>
                            <option value="BS Computer Engineering">BS Computer Engineering</option>
                            <option value="BS Civil Engineering">BS Civil Engineering</option>
                            <option value="BS Mechanical Engineering">BS Mechanical Engineering</option>
                            <option value="BS Electrical Engineering">BS Electrical Engineering</option>
                            <option value="BS Electronics Engineering">BS Electronics Engineering</option>
                            <option value="BS Architecture">BS Architecture</option>
                            <option value="BS Accountancy">BS Accountancy</option>
                            <option value="BS Business Administration">BS Business Administration</option>
                            <option value="BS Hospitality Management">BS Hospitality Management</option>
                            <option value="BS Tourism Management">BS Tourism Management</option>
                            <option value="BS Education">BS Education</option>
                            <option value="BS Psychology">BS Psychology</option>
                            <option value="BS Nursing">BS Nursing</option>
                            <option value="BS Criminology">BS Criminology</option>
                            <option value="BS Social Work">BS Social Work</option>
                            <option value="AB Communication">AB Communication</option>
                            <option value="AB Political Science">AB Political Science</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Email (Read-only)</label>
                        <input type="email" id="editStudentEmail" readonly
                               class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-slate-100 text-slate-600 cursor-not-allowed">
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="saveStudentInfo()" 
                            class="flex-1 h-11 bg-indigo-600 text-white text-base font-medium rounded-lg shadow-md hover:bg-indigo-700 transition-colors">
                        Save Changes
                    </button>
                    <button type="button" onclick="closeEditStudentModal()" 
                            class="flex-1 h-11 bg-slate-200 text-slate-700 text-base font-medium rounded-lg shadow-md hover:bg-slate-300 transition-colors">
                        Cancel
                    </button>
                </div>
            </div>
            
            <!-- Right Column: Live Digital ID Preview -->
            <div class="flex flex-col items-center">
                <h4 class="text-lg font-semibold text-slate-700 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                    </svg>
                    Digital ID Preview
                    <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-normal">Live</span>
                </h4>
                <div id="digitalIdPreviewContainer" class="w-full"></div>
                <p class="text-xs text-slate-400 mt-3 text-center">Drag & drop a photo onto the ID card, or click the photo area to upload</p>
                <button type="button" id="deletePhotoBtn" onclick="removeIdCardPhoto()" style="display:none;" class="mt-2 px-4 py-1.5 bg-red-500 text-white rounded-lg hover:bg-red-600 text-xs font-medium transition-colors">Remove Photo</button>
            </div>
        </div>
    </div>
</div>

<!-- Custom Confirmation Modal -->
<div id="customConfirmModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-[60]" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 transform transition-all scale-95 opacity-0" id="customConfirmContent">
        <div class="p-6">
            <div class="flex items-start gap-4">
                <div id="confirmIcon" class="flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center"></div>
                <div class="flex-1">
                    <h3 id="confirmTitle" class="text-lg font-semibold text-slate-800 mb-2"></h3>
                    <div id="confirmMessage" class="text-sm text-slate-600 space-y-1"></div>
                </div>
            </div>
        </div>
        <div class="border-t border-slate-200 p-4 flex gap-3">
            <button id="confirmCancelBtn" class="flex-1 h-11 px-4 bg-slate-100 text-slate-700 rounded-lg font-medium hover:bg-slate-200 transition-colors">
                Cancel
            </button>
            <button id="confirmOkBtn" class="flex-1 h-11 px-4 bg-sky-600 text-white rounded-lg font-medium hover:bg-sky-700 transition-colors shadow-sm">
                OK
            </button>
        </div>
    </div>
</div>

<!-- Toast Notification Container -->
<div id="toastContainer" class="fixed top-4 right-4 z-[70] space-y-3 pointer-events-none"></div>

<script>
// CSRF Token for JavaScript fetch requests
const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';

// ========================================
// CUSTOM MODAL & NOTIFICATION SYSTEM
// ========================================

// Custom Confirm Dialog
function showCustomConfirm(title, message, options = {}) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customConfirmModal');
        const content = document.getElementById('customConfirmContent');
        const titleEl = document.getElementById('confirmTitle');
        const messageEl = document.getElementById('confirmMessage');
        const iconEl = document.getElementById('confirmIcon');
        const okBtn = document.getElementById('confirmOkBtn');
        const cancelBtn = document.getElementById('confirmCancelBtn');
        
        // Set content
        titleEl.textContent = title;
        
        // Handle message (can be string or array of items)
        if (Array.isArray(message)) {
            messageEl.innerHTML = message.map(item => `<div class="flex items-start gap-2"><span>${item}</span></div>`).join('');
        } else {
            // Check if message contains HTML tags, if so use innerHTML, otherwise textContent for safety
            if (message.includes('<') && message.includes('>')) {
                messageEl.innerHTML = message;
            } else {
                messageEl.textContent = message;
            }
        }
        
        // Set icon based on type
        const type = options.type || 'warning';
        if (type === 'warning') {
            iconEl.className = 'flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center bg-orange-100';
            iconEl.innerHTML = '<svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
            okBtn.className = 'flex-1 h-11 px-4 bg-orange-600 text-white rounded-lg font-medium hover:bg-orange-700 transition-colors shadow-sm';
        } else if (type === 'success') {
            iconEl.className = 'flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center bg-green-100';
            iconEl.innerHTML = '<svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
            okBtn.className = 'flex-1 h-11 px-4 bg-green-600 text-white rounded-lg font-medium hover:bg-green-700 transition-colors shadow-sm';
        } else if (type === 'danger') {
            iconEl.className = 'flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center bg-red-100';
            iconEl.innerHTML = '<svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
            okBtn.className = 'flex-1 h-11 px-4 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition-colors shadow-sm';
        } else {
            iconEl.className = 'flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center bg-sky-100';
            iconEl.innerHTML = '<svg class="w-6 h-6 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
            okBtn.className = 'flex-1 h-11 px-4 bg-sky-600 text-white rounded-lg font-medium hover:bg-sky-700 transition-colors shadow-sm';
        }
        
        // Set button text
        okBtn.textContent = options.okText || 'OK';
        const showCancelButton = options.cancelText !== null;
        if (showCancelButton) {
            cancelBtn.textContent = options.cancelText || 'Cancel';
            cancelBtn.classList.remove('hidden');
            okBtn.classList.remove('w-full');
            okBtn.classList.add('flex-1');
        } else {
            cancelBtn.classList.add('hidden');
            okBtn.classList.remove('flex-1');
            okBtn.classList.add('w-full');
        }
        
        // Show modal with animation
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.classList.remove('hidden');
            content.classList.remove('scale-95', 'opacity-0');
            content.classList.add('scale-100', 'opacity-100');
        }, 10);
        
        // Handle buttons
        const handleOk = () => {
            closeCustomConfirm();
            resolve(true);
        };
        
        const handleCancel = () => {
            closeCustomConfirm();
            resolve(false);
        };
        
        okBtn.onclick = handleOk;
        cancelBtn.onclick = showCancelButton ? handleCancel : null;
        
        // Close on backdrop click
        modal.onclick = (e) => {
            if (e.target === modal) handleCancel();
        };
    });
}

function closeCustomConfirm() {
    const modal = document.getElementById('customConfirmModal');
    const content = document.getElementById('customConfirmContent');
    
    content.classList.remove('scale-100', 'opacity-100');
    content.classList.add('scale-95', 'opacity-0');
    
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }, 200);
}

// Toast Notification System
function showToast(message, type = 'success', duration = 5000) {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = 'pointer-events-auto transform transition-all duration-300 translate-x-full opacity-0';
    
    let bgClass, iconSvg, iconBg;
    
    if (type === 'success') {
        bgClass = 'bg-white border-l-4 border-green-500';
        iconBg = 'bg-green-100';
        iconSvg = '<svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
    } else if (type === 'error') {
        bgClass = 'bg-white border-l-4 border-red-500';
        iconBg = 'bg-red-100';
        iconSvg = '<svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
    } else if (type === 'warning') {
        bgClass = 'bg-white border-l-4 border-orange-500';
        iconBg = 'bg-orange-100';
        iconSvg = '<svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
    } else {
        bgClass = 'bg-white border-l-4 border-sky-500';
        iconBg = 'bg-sky-100';
        iconSvg = '<svg class="w-5 h-5 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    }
    
    toast.innerHTML = `
        <div class="${bgClass} rounded-lg shadow-lg p-4 flex items-start gap-3 min-w-[320px] max-w-md">
            <div class="${iconBg} rounded-full p-2 flex-shrink-0">
                ${iconSvg}
            </div>
            <div class="flex-1">
                <p class="text-sm font-medium text-slate-800">${message}</p>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="text-slate-400 hover:text-slate-600 flex-shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    `;
    
    container.appendChild(toast);
    
    // Trigger animation
    setTimeout(() => {
        toast.classList.remove('translate-x-full', 'opacity-0');
        toast.classList.add('translate-x-0', 'opacity-100');
    }, 10);
    
    // Auto remove
    if (duration > 0) {
        setTimeout(() => {
            toast.classList.add('translate-x-full', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
}

let currentStudentId = null;
let rfidInputListener = null;

function openCardRegistration(studentId) {
    currentStudentId = studentId;
    document.getElementById('cardModal').classList.remove('hidden');
    document.getElementById('cardModal').classList.add('flex');
    pollRFIDReader();
}

function closeCardModal() {
    // Clean up event listener
    if (rfidInputListener) {
        document.removeEventListener('keypress', rfidInputListener);
        rfidInputListener = null;
    }
    
    document.getElementById('cardModal').classList.add('hidden');
    document.getElementById('cardModal').classList.remove('flex');
    currentStudentId = null;
}

function pollRFIDReader() {
    document.getElementById('rfidStatus').innerHTML = `
        <div class="space-y-4">
            <div class="animate-pulse inline-flex items-center">
                <div class="h-3 w-3 bg-blue-500 rounded-full mr-2"></div>
                <span class="text-slate-600">Waiting for card tap...</span>
            </div>
            <input 
                type="text" 
                id="rfidInput" 
                autofocus 
                autocomplete="off"
                placeholder="Tap card on scanner..."
                class="w-full px-4 py-3 border-2 border-blue-300 rounded-lg text-center font-mono text-lg focus:border-blue-500 focus:ring-4 focus:ring-blue-100 transition-all"
                style="letter-spacing: 2px;"
            >
            <p class="text-xs text-slate-500">R20XC-USB Scanner Ready • Accepts 10-digit card IDs</p>
        </div>
    `;
    
    const input = document.getElementById('rfidInput');
    input.focus();
    
    // Buffer for card data (R20XC sends UID followed by Enter)
    let cardBuffer = '';
    let bufferTimeout = null;
    
    // Listen for keyboard input from RFID reader - Use keydown instead of keypress
    rfidInputListener = function(e) {
        // Enter key signals end of card scan - process buffer FIRST
        if (e.key === 'Enter') {
            e.preventDefault();
            
            const uid = cardBuffer.trim();
            console.log('Admin RFID Scan - Buffer:', cardBuffer, 'Trimmed:', uid, 'Length:', uid.length);
            
            // More flexible validation - just check if something was scanned
            if (uid.length >= 4) {
                document.getElementById('rfidStatus').innerHTML = `
                    <div class="text-green-600 font-medium">
                        ✓ Card detected: ${uid}
                    </div>
                    <div class="text-sm text-slate-500 mt-2">Processing...</div>
                `;
                
                // Remove listener to prevent duplicate scans
                document.removeEventListener('keydown', rfidInputListener);
                rfidInputListener = null;
                
                // Register the card (keep original format - don't convert to uppercase for decimal numbers)
                registerCard(currentStudentId, uid);
            } else {
                document.getElementById('rfidStatus').innerHTML = `
                    <div class="text-red-600">No card detected or invalid scan. Please try again.</div>
                    <div class="text-xs text-slate-500 mt-1">Scanned: "${uid}" (${uid.length} chars)</div>
                `;
                cardBuffer = '';
                input.value = '';
                setTimeout(() => pollRFIDReader(), 2000);
            }
            
            cardBuffer = '';
            clearTimeout(bufferTimeout);
            return;
        }
        
        // Accumulate all other single-character keys (numbers)
        if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            cardBuffer += e.key;
            input.value = cardBuffer;
            console.log('Admin - Character captured:', e.key, 'Buffer now:', cardBuffer);
            
            // Reset timeout (R20XC sends data quickly, within ~100ms)
            clearTimeout(bufferTimeout);
            bufferTimeout = setTimeout(() => {
                // If buffer doesn't complete, reset
                if (cardBuffer.length > 0 && cardBuffer.length < 4) {
                    console.warn('Admin - Buffer timeout, clearing:', cardBuffer);
                    cardBuffer = '';
                    input.value = '';
                }
            }, 500);
        }
    };
    
    document.addEventListener('keydown', rfidInputListener);
    
    // Also handle direct input field submission (manual entry fallback)
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const uid = this.value.trim();
            if (uid.length >= 4) {
                document.removeEventListener('keypress', rfidInputListener);
                rfidInputListener = null;
                registerCard(currentStudentId, uid);
            }
        }
    });
}

function registerCard(studentId, uid) {
    document.getElementById('rfidStatus').innerHTML = `
        <div class="flex items-center justify-center">
            <svg class="animate-spin h-8 w-8 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="ml-3 text-slate-600">Registering card...</span>
        </div>
    `;
    
    fetch('register_card.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            student_id: studentId,
            rfid_uid: uid
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('rfidStatus').innerHTML = `
                <div class="text-green-600 font-semibold text-lg">
                    ✓ Card registered successfully!
                </div>
            `;
            setTimeout(() => {
                closeCardModal();
                location.reload();
            }, 1500);
        } else {
            document.getElementById('rfidStatus').innerHTML = `
                <div class="text-red-600 font-medium">${data.error}</div>
                <button onclick="pollRFIDReader()" class="mt-4 px-4 py-2 bg-blue-500 text-white rounded-lg">
                    Try Again
                </button>
            `;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('rfidStatus').innerHTML = `
            <div class="text-red-600">Network error. Please check your connection.</div>
            <button onclick="pollRFIDReader()" class="mt-4 px-4 py-2 bg-blue-500 text-white rounded-lg">
                Try Again
            </button>
        `;
    });
}

function unregisterCard(studentId) {
    if (confirm('Are you sure you want to unregister this RFID card?')) {
        fetch('unregister_card.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                student_id: studentId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.error || 'Failed to unregister card');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to unregister card');
        });
    }
}

// Toggle RFID Lost Status (Enable/Disable Mark Lost ID)
async function toggleRfidLostStatus(cardId, studentId, rfidUid, studentName, studentEmail, markAsLost) {
    console.log('toggleRfidLostStatus called:', { cardId, studentId, rfidUid, studentName, studentEmail, markAsLost });
    
    if (!cardId && !studentId) {
        showToast('Card reference is missing. Please refresh the page and try again.', 'error');
        return;
    }
    
    let confirmTitle, confirmMessages, actionText;
    
    if (markAsLost) {
        confirmTitle = `Enable Mark Lost ID for ${studentName}?`;
        confirmMessages = [
            '✉️ Send email notification to student',
            '🚫 Temporarily disable their RFID card',
            '📱 Student must use Digital ID QR code for entry',
            `📧 Student must email Student Services about lost card`,
            '',
            `<strong>Student Email:</strong> ${studentEmail}`
        ];
        actionText = 'mark_lost';
    } else {
        confirmTitle = `Disable Mark Lost ID for ${studentName}?`;
        confirmMessages = [
            '✅ Re-enable their RFID card',
            '✉️ Send confirmation email to student',
            '📱 RFID card can be used for entry again'
        ];
        actionText = 'mark_found';
    }
    
    const confirmed = await showCustomConfirm(confirmTitle, confirmMessages, {
        type: markAsLost ? 'warning' : 'success',
        okText: markAsLost ? 'Enable Mark Lost ID' : 'Disable Mark Lost ID',
        cancelText: 'Cancel'
    });
    
    if (!confirmed) return;
    
    // Show loading toast
    const loadingToast = showToast(
        markAsLost ? 'Marking RFID as lost...' : 'Re-enabling RFID card...',
        'info',
        0 // Don't auto-dismiss
    );
    
    const payload = {
        card_id: cardId,
        student_id: studentId,
        rfid_uid: rfidUid,
        action: actionText,
        student_email: studentEmail,
        student_name: studentName,
        csrf_token: csrfToken
    };
    
    console.log('Sending request:', payload);
    
    try {
        const response = await fetch('mark_lost_rfid.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });
        
        console.log('Response status:', response.status);
        const data = await response.json();
        console.log('Response data:', data);
        
        // Remove loading toast
        const toastContainer = document.getElementById('toastContainer');
        toastContainer.innerHTML = '';
        
        if (data.success) {
            showToast(
                data.message || '✓ Status updated successfully',
                'success',
                3000
            );
            
            // Reload after toast is visible
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(
                'Error: ' + (data.error || 'Unknown error occurred'),
                'error'
            );
        }
    } catch (error) {
        console.error('Error:', error);
        
        // Remove loading toast
        const toastContainer = document.getElementById('toastContainer');
        toastContainer.innerHTML = '';
        
        showToast('Network error. Please try again.', 'error');
    }
}

// PHASE 2: Toggle Guardian Notifications
function toggleGuardianNotifications(enabled) {
    fetch('toggle_guardian_notifications.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            enabled: enabled,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const status = enabled ? 'ENABLED' : 'DISABLED';
            alert(`✓ Guardian notifications ${status} successfully`);
        } else {
            alert('Error: ' + (data.error || 'Failed to update settings'));
            // Revert toggle on error
            document.getElementById('guardianNotificationsToggle').checked = !enabled;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
        // Revert toggle on error
        document.getElementById('guardianNotificationsToggle').checked = !enabled;
    });
}

function confirmDelete(studentId) {
    // Get student name from the DOM for better UX
    const studentCard = event.target.closest('.bg-white');
    const studentName = studentCard?.querySelector('h3')?.textContent || 'this student';
    
    showConfirmModal({
        title: `Delete account for ${studentName}?`,
        message: '⚠️ WARNING: This action cannot be undone!',
        items: [
            'Permanently delete account',
            'Remove all student data',
            'Cannot be recovered'
        ],
        confirmText: 'Delete',
        cancelText: 'Cancel',
        confirmClass: 'bg-red-600 hover:bg-red-700',
        onConfirm: () => executeDelete(studentId, studentName)
    });
}

function executeDelete(studentId, studentName) {
    showToast('Deleting account...', 'warning', 0);
    
    fetch('delete_account.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            student_id: studentId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Account deleted successfully', 'success', 600);
            setTimeout(() => {
                location.reload();
            }, 600);
        } else {
            showToast(data.error || 'Failed to delete account', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to delete account', 'error');
    });
}

function viewCardDetails(studentId, name, uid, registeredAt, profilePicture) {
    const modal = document.getElementById('cardDetailsModal');
    const content = document.getElementById('cardDetailsContent');
    
    // Get first letter for fallback avatar
    const firstLetter = name.charAt(0).toUpperCase();
    const colors = ['bg-blue-500', 'bg-green-500', 'bg-yellow-500', 'bg-red-500', 'bg-purple-500', 'bg-pink-500'];
    const colorIndex = firstLetter.charCodeAt(0) % colors.length;
    const bgColor = colors[colorIndex];
    
    content.innerHTML = `
        <div class="space-y-4">
            ${profilePicture ? `
                <div class="flex justify-center mb-4">
                    <img src="../assets/images/profiles/${profilePicture}" 
                         alt="${name}" 
                         class="w-32 h-32 rounded-full object-cover border-4 border-blue-200 shadow-lg">
                </div>
            ` : `
                <div class="flex justify-center mb-4">
                    <div class="w-32 h-32 rounded-full ${bgColor} border-4 border-blue-200 shadow-lg flex items-center justify-center text-white text-5xl font-bold">
                        ${firstLetter}
                    </div>
                </div>
            `}
            <div class="bg-slate-50 p-4 rounded-lg">
                <p class="text-sm text-slate-600 mb-1">Student Name</p>
                <p class="font-medium text-slate-800">${name}</p>
            </div>
            <div class="bg-slate-50 p-4 rounded-lg">
                <p class="text-sm text-slate-600 mb-1">RFID UID</p>
                <code class="font-mono text-lg text-slate-800">${uid}</code>
            </div>
            <div class="bg-slate-50 p-4 rounded-lg">
                <p class="text-sm text-slate-600 mb-1">Registered On</p>
                <p class="font-medium text-slate-800">${new Date(registeredAt).toLocaleString()}</p>
            </div>
        </div>
    `;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeCardDetailsModal() {
    document.getElementById('cardDetailsModal').classList.add('hidden');
    document.getElementById('cardDetailsModal').classList.remove('flex');
}

function viewViolationDetails(studentId, name, studentNumber, email, rfidUid, violationCount) {
    const modal = document.getElementById('violationDetailsModal');
    const content = document.getElementById('violationDetailsContent');
    
    content.innerHTML = `
        <div class="space-y-4">
            <div class="bg-red-50 border-2 border-red-200 rounded-lg p-4">
                <div class="flex items-center gap-2 mb-2">
                    <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span class="font-bold text-red-800">Maximum Violation Alert</span>
                </div>
                <p class="text-sm text-red-700">This student has reached <strong>${violationCount} strikes</strong> and requires immediate attention.</p>
            </div>
            
            <div class="bg-slate-50 p-4 rounded-lg">
                <p class="text-sm text-slate-600 mb-1 font-medium">Full Name</p>
                <p class="text-lg font-semibold text-slate-800">${name}</p>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-slate-50 p-4 rounded-lg">
                    <p class="text-sm text-slate-600 mb-1 font-medium">Student Number</p>
                    <p class="font-semibold text-slate-800">${studentNumber}</p>
                </div>
                <div class="bg-slate-50 p-4 rounded-lg">
                    <p class="text-sm text-slate-600 mb-1 font-medium">Email Address</p>
                    <p class="font-semibold text-slate-800 text-sm">${email}</p>
                </div>
            </div>
            
            <div class="bg-slate-50 p-4 rounded-lg">
                <p class="text-sm text-slate-600 mb-1 font-medium">RFID Card UID</p>
                <code class="font-mono text-lg text-slate-800 bg-white px-3 py-2 rounded border border-slate-200 inline-block">${rfidUid}</code>
            </div>
            
            <div class="bg-slate-50 p-4 rounded-lg">
                <p class="text-sm text-slate-600 mb-1 font-medium">Current Violation Count</p>
                <div class="flex items-center gap-3">
                    <span class="text-3xl font-bold text-red-600">${violationCount}</span>
                    <span class="text-slate-600">strike${violationCount !== '1' ? 's' : ''}</span>
                </div>
            </div>
        </div>
    `;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeViolationDetailsModal() {
    document.getElementById('violationDetailsModal').classList.add('hidden');
    document.getElementById('violationDetailsModal').classList.remove('flex');
}

function confirmClearViolation(studentId, studentName) {
    if (confirm(`Are you sure you want to clear all violations for ${studentName}?\n\nThis will reset their violation count to 0.`)) {
        clearViolation(studentId);
    }
}

function clearViolation(studentId) {
    fetch('clear_violation.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            student_id: studentId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            alert('Violations cleared successfully!');
            location.reload();
        } else {
            alert(data.error || 'Failed to clear violations');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to clear violations. Please try again.');
    });
}

// Approve student account
function approveStudent(studentId, studentName) {
    // Show modern confirmation modal
    showConfirmModal({
        title: `Approve account for ${studentName}?`,
        message: 'This will:',
        items: [
            'Activate their account',
            'Send approval email',
            'Allow them to log in'
        ],
        confirmText: 'OK',
        cancelText: 'Cancel',
        confirmClass: 'bg-blue-600 hover:bg-blue-700',
        onConfirm: () => {
            executeApproval(studentId, studentName);
        }
    });
}

function executeApproval(studentId, studentName) {
    fetch('verify_account.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            student_id: studentId,
            action: 'approve'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show toast and reload INSTANTLY
            showToast('Approved!', 'success', 0);
            
            // Fire email in background - don't wait for it
            fetch('send_approval_email.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ student_id: studentId })
            }).catch(() => {}); // Ignore any email errors
            
            // Reload immediately - don't wait for email
            location.reload();
        } else {
            showToast(data.error || 'Failed to approve account', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to approve. Please try again.', 'error');
    });
}

// Deny student account
function denyStudent(studentId, studentName) {
    if (!confirm(`Deny and remove account for ${studentName}?\n\n⚠️ WARNING: This will:\n✗ Delete their account permanently\n✗ Send denial email notification\n\nThis action cannot be undone!`)) {
        return;
    }
    
    // Double confirmation for destructive action
    if (!confirm(`Are you absolutely sure?\n\nStudent: ${studentName}\nAction: DENY & DELETE\n\nType reason in next dialog...`)) {
        return;
    }
    
    // Show loading state
    const btn = event.target.closest('button');
    const originalContent = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<svg class="animate-spin h-5 w-5 mx-auto" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
    
    fetch('verify_account.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            student_id: studentId,
            action: 'deny'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message with animation
            btn.innerHTML = '<svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
            
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            alert(data.error || 'Failed to deny account');
            btn.disabled = false;
            btn.innerHTML = originalContent;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to deny account. Please try again.');
        btn.disabled = false;
        btn.innerHTML = originalContent;
    });
}

// Sidebar toggle functionality
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    // Toggle classes
    sidebar.classList.toggle('sidebar-hidden');
    overlay.classList.toggle('active');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize mobile behavior on small screens
    function initMobileSidebar() {
        if (window.innerWidth <= 768) {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            // Start with sidebar hidden on mobile
            if (!sidebar.classList.contains('sidebar-hidden')) {
                sidebar.classList.add('sidebar-hidden');
            }
            overlay.classList.remove('active');
        } else {
            // On desktop, always show sidebar
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.remove('sidebar-hidden');
            overlay.classList.remove('active');
        }
    }
    
    initMobileSidebar();
    
    // Re-initialize on window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            initMobileSidebar();
        }, 250);
    });
});

// ========================================
// EDIT STUDENT FUNCTIONS
// ========================================

function openEditStudentModal(userId, name, studentId, email, profilePicture, course) {
    // Set form values
    document.getElementById('editStudentUserId').value = userId;
    document.getElementById('editStudentName').value = name;
    document.getElementById('editStudentId').value = studentId;
    document.getElementById('editStudentEmail').value = email;
    
    // Set course dropdown
    const courseSelect = document.getElementById('editStudentCourse');
    if (course) {
        // Try exact match first
        let found = false;
        for (let i = 0; i < courseSelect.options.length; i++) {
            if (courseSelect.options[i].value === course) {
                courseSelect.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) {
            // Add as custom option if not in the list
            const opt = document.createElement('option');
            opt.value = course;
            opt.textContent = course;
            courseSelect.appendChild(opt);
            courseSelect.value = course;
        }
    } else {
        courseSelect.selectedIndex = 0;
    }
    
    // Show modal
    document.getElementById('editStudentModal').classList.remove('hidden');
    document.getElementById('editStudentModal').classList.add('flex');
    
    // Initialize Digital ID Preview
    try {
        if (window.digitalIdPreview) {
            window.digitalIdPreview.destroy();
        }
        window.digitalIdPreview = new DigitalIdCard('#digitalIdPreviewContainer', {
            templateSrc: '../assets/images/id-card-template.png',
            student: {
                name: name || '',
                studentId: studentId || '',
                course: course || '',
                email: email || '',
                profilePicture: (profilePicture && profilePicture !== '') 
                    ? '../assets/images/profiles/' + profilePicture 
                    : null
            }
        });
        window.digitalIdPreview.render();
        // Show delete button if student already has a photo
        var delBtn = document.getElementById('deletePhotoBtn');
        if (delBtn) delBtn.style.display = (profilePicture && profilePicture !== '') ? 'inline-block' : 'none';
        setTimeout(function(){ window.digitalIdPreview.animateEntrance(); }, 50);
    } catch(idErr) {
        console.error('DigitalIdCard error:', idErr);
        document.getElementById('digitalIdPreviewContainer').innerHTML = '<div style="padding:20px;background:#fee2e2;border-radius:8px;color:#dc2626;text-align:center;">ID Card Error: ' + idErr.message + '</div>';
    }
}

// Live update the Digital ID card as the admin types
function updateDigitalIdPreview() {
    if (!window.digitalIdPreview) return;
    window.digitalIdPreview.update({
        name: document.getElementById('editStudentName').value.trim(),
        studentId: document.getElementById('editStudentId').value.trim(),
        course: document.getElementById('editStudentCourse').value
    });
}

function enforceNumericStudentId(inputEl) {
    if (!inputEl) return;
    inputEl.value = inputEl.value.replace(/\D/g, '');
}

function closeEditStudentModal() {
    document.getElementById('editStudentModal').classList.add('hidden');
    document.getElementById('editStudentModal').classList.remove('flex');
    
    // Destroy Digital ID preview
    if (window.digitalIdPreview) {
        window.digitalIdPreview.destroy();
        window.digitalIdPreview = null;
    }
    var delBtn = document.getElementById('deletePhotoBtn');
    if (delBtn) delBtn.style.display = 'none';
}

// Remove photo from ID card (and delete from server)
async function removeIdCardPhoto() {
    var userId = document.getElementById('editStudentUserId').value;
    
    // Remove from preview immediately
    if (window.digitalIdPreview) {
        window.digitalIdPreview.removePhoto();
    }
    
    // Delete from server if it exists
    try {
        var response = await fetch('delete_student_picture.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ user_id: userId })
        });
        var data = await response.json();
        if (!data.success && data.error && !data.error.includes('No profile picture')) {
            console.warn('Photo delete warning:', data.error);
        }
    } catch(e) {
        console.warn('Photo delete error (non-fatal):', e);
    }
}

async function saveStudentInfo() {
    var userId = document.getElementById('editStudentUserId').value;
    var name = document.getElementById('editStudentName').value.trim();
    var studentIdInput = document.getElementById('editStudentId');
    enforceNumericStudentId(studentIdInput);
    var studentId = studentIdInput.value.trim();
    var course = document.getElementById('editStudentCourse').value;
    
    if (!name) { alert('Please enter student name'); return; }
    if (!studentId) { alert('Please enter student ID'); return; }
    if (!/^\d+$/.test(studentId)) { alert('Student ID must contain numbers only'); return; }
    if (studentId.length < 3) { alert('Student ID must be at least 3 characters'); return; }
    
    try {
        // 1. Upload photo first if there's a pending one
        if (window.digitalIdPreview && window.digitalIdPreview.hasPendingPhoto()) {
            var formData = new FormData();
            formData.append('student_id', userId);
            formData.append('file', window.digitalIdPreview.getPendingPhotoFile());
            
            var photoResp = await fetch('upload_student_picture.php', {
                method: 'POST',
                body: formData
            });
            var photoData = await photoResp.json();
            if (!photoData.success) {
                alert('Photo upload failed: ' + (photoData.error || 'Unknown error'));
                return;
            }
        }
        
        // 2. Save student info
        var response = await fetch('update_student_info.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                user_id: userId,
                name: name,
                student_id: studentId,
                course: course
            })
        });
        
        var data = await response.json();
        
        if (data.success) {
            alert('Student information updated successfully!');
            closeEditStudentModal();
            location.reload();
        } else {
            if (data.error && data.error.includes('no changes made')) {
                alert('Information saved.');
                closeEditStudentModal();
                location.reload();
            } else {
                alert('Update failed: ' + (data.error || 'Unknown error'));
            }
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to update student information. Please try again.');
    }
}

// ========================================
// ========================================
// REGISTERED CARDS SEARCH FUNCTIONALITY
// ========================================

// ========================================
// RFID CHECKER FUNCTIONS
// ========================================

// RFID Checker – scanner listener + lookup
(function() {
    const checkerInput = document.getElementById('rfidCheckerInput');
    if (!checkerInput) return;

    let checkerBuffer = '';
    let checkerTimeout = null;

    // Listen for RFID scanner keyboard emulation on the input
    checkerInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            lookupRfidCard();
            return;
        }
    });

    // Also listen globally for fast scanner input when page is focused
    document.addEventListener('keydown', function(e) {
        // Only capture when rfid_checker section is active and no modal is open
        if (!document.getElementById('rfidCheckerInput')) return;
        const activeEl = document.activeElement;
        // If already focused on the checker input, let the input handler deal with it
        if (activeEl && activeEl.id === 'rfidCheckerInput') return;
        // Skip if user is typing in another input/textarea
        if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT')) return;

        if (e.key === 'Enter' && checkerBuffer.length >= 4) {
            e.preventDefault();
            checkerInput.value = checkerBuffer;
            checkerBuffer = '';
            clearTimeout(checkerTimeout);
            lookupRfidCard();
            return;
        }
        if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            checkerBuffer += e.key;
            clearTimeout(checkerTimeout);
            checkerTimeout = setTimeout(() => { checkerBuffer = ''; }, 300);
        }
    });
})();

function lookupRfidCard() {
    const input = document.getElementById('rfidCheckerInput');
    const uid = (input ? input.value.trim() : '');
    const resultDiv = document.getElementById('rfidCheckerResult');

    if (!uid) {
        resultDiv.innerHTML = `
            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
                <p class="text-yellow-700 font-medium">Please enter or scan an RFID UID first.</p>
            </div>`;
        return;
    }

    resultDiv.innerHTML = `
        <div class="bg-white rounded-xl shadow-sm p-8 text-center">
            <svg class="animate-spin h-8 w-8 text-blue-500 mx-auto mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <p class="text-slate-500">Looking up RFID card...</p>
        </div>`;

    fetch('check_rfid.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ rfid_uid: uid, csrf_token: csrfToken })
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            resultDiv.innerHTML = `
                <div class="bg-red-50 border border-red-200 rounded-xl p-6">
                    <div class="flex items-center gap-3 mb-2">
                        <span class="text-3xl">❌</span>
                        <div>
                            <h3 class="text-lg font-bold text-red-800">Not Found</h3>
                            <p class="text-red-600 text-sm">${escapeHtml(data.error || 'This RFID UID is not registered to any student.')}</p>
                        </div>
                    </div>
                    <div class="mt-3 bg-white rounded-lg p-3 border border-red-100">
                        <p class="text-slate-500 text-sm">Scanned UID: <code class="bg-slate-100 px-2 py-1 rounded font-mono">${escapeHtml(uid)}</code></p>
                    </div>
                </div>`;
            return;
        }

        const s = data.student;
        const card = data.card;

        // Status badge
        let statusBadge = '';
        if (card && card.is_lost == 1) {
            statusBadge = '<span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-700">🚫 LOST / DISABLED</span>';
        } else if (card) {
            statusBadge = '<span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-700">✅ Active</span>';
        } else {
            statusBadge = '<span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-semibold bg-blue-100 text-blue-700">📋 Registered (no card record)</span>';
        }

        // Violation severity
        let violationColor = 'green';
        let violationLabel = 'No violations';
        if (s.violation_count >= 4) {
            violationColor = 'red'; violationLabel = 'BLOCKED — Max violations exceeded';
        } else if (s.violation_count === 3) {
            violationColor = 'orange'; violationLabel = 'FINAL WARNING — 3 strikes';
        } else if (s.violation_count === 2) {
            violationColor = 'yellow'; violationLabel = 'Warning — 2 strikes';
        } else if (s.violation_count === 1) {
            violationColor = 'blue'; violationLabel = '1 strike';
        }

        const lastScan = data.last_scan
            ? new Date(data.last_scan).toLocaleString()
            : '<span class="text-slate-400">Never scanned</span>';

        const lostDate = (card && card.is_lost == 1 && card.lost_at)
            ? new Date(card.lost_at).toLocaleString()
            : null;

        resultDiv.innerHTML = `
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        ${s.profile_picture
                            ? '<img src="../assets/images/profiles/' + escapeHtml(s.profile_picture) + '" class="w-12 h-12 rounded-full object-cover border-2 border-slate-200">'
                            : '<div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold text-lg">' + escapeHtml(s.name.charAt(0).toUpperCase()) + '</div>'
                        }
                        <div>
                            <h3 class="text-lg font-bold text-slate-800">${escapeHtml(s.name)}</h3>
                            <p class="text-sm text-slate-500">${escapeHtml(s.student_id)} ${s.course ? '• ' + escapeHtml(s.course) : ''}</p>
                        </div>
                    </div>
                    ${statusBadge}
                </div>

                <!-- Details Grid -->
                <div class="p-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- RFID UID -->
                        <div class="bg-slate-50 rounded-lg p-4">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">RFID UID</p>
                            <p class="text-lg font-mono font-semibold text-slate-800">${escapeHtml(s.rfid_uid)}</p>
                        </div>

                        <!-- Email -->
                        <div class="bg-slate-50 rounded-lg p-4">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Email</p>
                            <p class="text-sm font-medium text-slate-800 break-all">${escapeHtml(s.email)}</p>
                        </div>

                        <!-- Violations -->
                        <div class="bg-${violationColor}-50 rounded-lg p-4 border border-${violationColor}-200">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Violations</p>
                            <p class="text-2xl font-bold text-${violationColor}-700">${escapeHtml(String(s.violation_count))}</p>
                            <p class="text-xs text-${violationColor}-600 mt-1">${violationLabel}</p>
                        </div>

                        <!-- Last Scan -->
                        <div class="bg-slate-50 rounded-lg p-4">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Last Gate Scan</p>
                            <p class="text-sm font-medium text-slate-800">${lastScan}</p>
                        </div>

                        <!-- Account Status -->
                        <div class="bg-slate-50 rounded-lg p-4">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Account Status</p>
                            <p class="text-sm font-semibold ${s.status === 'Active' ? 'text-green-700' : 'text-yellow-700'}">${escapeHtml(s.status)}</p>
                        </div>

                        <!-- Registered At -->
                        <div class="bg-slate-50 rounded-lg p-4">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">RFID Registered</p>
                            <p class="text-sm font-medium text-slate-800">${s.rfid_registered_at ? new Date(s.rfid_registered_at).toLocaleString() : '<span class=&quot;text-slate-400&quot;>Unknown</span>'}</p>
                        </div>
                    </div>

                    ${card && card.is_lost == 1 ? `
                    <div class="mt-4 bg-red-50 border-2 border-red-300 rounded-lg p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-xl">🚫</span>
                            <h4 class="font-bold text-red-800">Card Marked as LOST</h4>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                            <div>
                                <span class="text-red-600 font-medium">Lost Date:</span>
                                <span class="text-red-800">${lostDate || 'Unknown'}</span>
                            </div>
                            <div>
                                <span class="text-red-600 font-medium">Reason:</span>
                                <span class="text-red-800">${escapeHtml(card.lost_reason || 'No reason provided')}</span>
                            </div>
                        </div>
                        <p class="text-red-700 text-xs mt-2 font-medium">⚠️ This card is disabled. Gate scans will be rejected until an admin re-enables it.</p>
                    </div>
                    ` : ''}
                </div>
            </div>`;
    })
    .catch(err => {
        console.error('RFID Checker error:', err);
        resultDiv.innerHTML = `
            <div class="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
                <p class="text-red-700 font-medium">Failed to look up RFID card. Please try again.</p>
            </div>`;
    });
}

// Real-time search for registered cards
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('registeredCardSearch');
    
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase().trim();
            const cards = document.querySelectorAll('.registered-card-item');
            let visibleCount = 0;
            
            cards.forEach(card => {
                const studentId = card.getAttribute('data-student-id');
                const studentName = card.getAttribute('data-student-name');
                
                // Search in both student ID and name
                if (studentId.includes(searchTerm) || studentName.includes(searchTerm)) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Update result count
            const resultCount = document.getElementById('searchResultCount');
            if (resultCount) {
                if (searchTerm === '') {
                    resultCount.textContent = '';
                } else {
                    resultCount.textContent = `Showing ${visibleCount} of ${cards.length} registered card${cards.length !== 1 ? 's' : ''}`;
                }
            }
        });
        
        // Clear search on Escape key
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                searchInput.blur();
            }
        });
    }
});

// ========================================
// AUDIT LOG FUNCTIONS
// ========================================

// Show audit log details modal
function showAuditDetails(log) {
    try {
        const details = JSON.parse(log.details);
        const detailsHtml = Object.entries(details).map(([key, value]) => {
            // Special handling for UPDATE_STUDENT changes object
            if (key === 'changes' && value && typeof value === 'object' && !Array.isArray(value)) {
                const changeRows = Object.entries(value).map(([field, change]) => {
                    const fromValue = change && typeof change === 'object' && change.from !== undefined ? String(change.from) : '';
                    const toValue = change && typeof change === 'object' && change.to !== undefined ? String(change.to) : '';

                    return `
                        <div class="py-2 border-b border-slate-100 last:border-b-0">
                            <div class="font-medium text-slate-700">${field.replace(/_/g, ' ').toUpperCase()}</div>
                            <div class="text-sm text-slate-600 mt-1">
                                <span class="text-slate-500">From:</span> ${escapeHtml(fromValue || '—')}
                            </div>
                            <div class="text-sm text-slate-600">
                                <span class="text-slate-500">To:</span> ${escapeHtml(toValue || '—')}
                            </div>
                        </div>
                    `;
                }).join('');

                return `
                    <div class="py-2 border-b border-slate-100">
                        <div class="font-medium text-slate-700 mb-2">CHANGES:</div>
                        <div class="bg-white rounded-lg p-3 border border-slate-200">
                            ${changeRows || '<div class="text-sm text-slate-500">No field changes recorded</div>'}
                        </div>
                    </div>
                `;
            }

            let displayValue = value;
            if (value === null || value === undefined || value === '') {
                displayValue = '—';
            } else if (typeof value === 'object') {
                displayValue = JSON.stringify(value);
            }

            return `
                <div class="flex justify-between py-2 border-b border-slate-100">
                    <span class="font-medium text-slate-700">${key.replace(/_/g, ' ').toUpperCase()}:</span>
                    <span class="text-slate-600">${escapeHtml(String(displayValue))}</span>
                </div>
            `;
        }).join('');
        
        showCustomConfirm(
            `Audit Log Details - ${log.action_type.replace(/_/g, ' ')}`,
            `
                <div class="text-left space-y-3">
                    <div class="bg-slate-50 p-4 rounded-lg space-y-2">
                        ${detailsHtml}
                    </div>
                    <div class="text-xs text-slate-500 mt-4 pt-4 border-t border-slate-200">
                        <div>
                            <div><strong>Timestamp:</strong> ${new Date(log.created_at).toLocaleString()}</div>
                        </div>
                    </div>
                </div>
            `,
            {
                type: 'info',
                okText: 'Close',
                cancelText: null
            }
        );
    } catch (error) {
        console.error('Error displaying audit details:', error);
        showToast('Failed to display audit details', 'error');
    }
}

// Apply audit log filters
async function applyAuditFilters(silent = false) {
    const actionType = document.getElementById('filterActionType').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    
    const params = new URLSearchParams({
        action_type: actionType,
        date_from: dateFrom,
        date_to: dateTo
    });
    
    try {
        const response = await fetch(`filter_audit_logs.php?${params}`);
        const data = await response.json();
        
        if (data.success) {
            updateAuditTable(data.logs);
            if (!silent) {
                showToast(`Found ${data.count} audit log${data.count !== 1 ? 's' : ''}`, 'success', 2000);
            }
        } else {
            if (!silent) {
                showToast(data.error || 'Failed to filter logs', 'error');
            }
        }
    } catch (error) {
        console.error('Error:', error);
        if (!silent) {
            showToast('Failed to apply filters', 'error');
        }
    }
}

// Update audit table with filtered results
function updateAuditTable(logs) {
    const tbody = document.getElementById('auditLogTableBody');
    
    if (logs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-12 text-slate-600">
                    <svg class="w-16 h-16 mx-auto text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <p class="font-medium text-slate-700 mb-1">No audit logs found</p>
                    <p class="text-sm text-slate-500">Try adjusting your filter criteria</p>
                </td>
            </tr>
        `;
        return;
    }
    
    const actionColors = {
        'APPROVE_STUDENT': 'bg-green-100 text-green-800',
        'DENY_STUDENT': 'bg-red-100 text-red-800',
        'REGISTER_RFID': 'bg-blue-100 text-blue-800',
        'UNREGISTER_RFID': 'bg-yellow-100 text-yellow-800',
        'MARK_LOST': 'bg-orange-100 text-orange-800',
        'MARK_FOUND': 'bg-emerald-100 text-emerald-800',
        'UPDATE_STUDENT': 'bg-indigo-100 text-indigo-800',
        'DELETE_STUDENT': 'bg-red-100 text-red-800'
    };
    
    tbody.innerHTML = logs.map(log => {
        const date = new Date(log.created_at);
        const actionColor = actionColors[log.action_type] || 'bg-slate-100 text-slate-800';
        
        return `
            <tr class="border-b border-slate-100 hover:bg-slate-50 transition-colors">
                <td class="py-3 px-4 text-sm text-slate-600">
                    ${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}<br>
                    <span class="text-xs text-slate-500">${date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                </td>
                <td class="py-3 px-4 text-sm font-medium text-slate-800">
                    ${escapeHtml(log.admin_name)}
                </td>
                <td class="py-3 px-4">
                    <span class="${actionColor} text-xs font-semibold px-2 py-1 rounded-full">
                        ${log.action_type.replace(/_/g, ' ')}
                    </span>
                </td>
                <td class="py-3 px-4 text-sm text-slate-700">
                    ${log.target_name ? `
                        <strong>${escapeHtml(log.target_name)}</strong><br>
                        <span class="text-xs text-slate-500">${log.target_type.charAt(0).toUpperCase() + log.target_type.slice(1)} ID: ${log.target_id}</span>
                    ` : '<span class="text-slate-500">N/A</span>'}
                </td>
                <td class="py-3 px-4 text-sm text-slate-600">
                    ${escapeHtml(log.description)}
                </td>
                <td class="py-3 px-4 text-center">
                    ${log.details ? `
                        <button onclick='showAuditDetails(${JSON.stringify(log).replace(/'/g, "\\'")})' 
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium transition-colors">
                            View Details
                        </button>
                    ` : '<span class="text-slate-400 text-sm">-</span>'}
                </td>
            </tr>
        `;
    }).join('');
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ========================================
// AUDIT LOG LIVE REFRESH
// ========================================
let auditLiveInterval = null;
let auditLiveEnabled = false;

function toggleAuditLiveRefresh() {
    auditLiveEnabled = !auditLiveEnabled;
    const indicator = document.getElementById('auditLiveIndicator');
    const toggleBtn = document.getElementById('auditLiveToggle');
    const toggleText = document.getElementById('auditLiveToggleText');
    
    if (auditLiveEnabled) {
        // Start live refresh every 5 seconds
        auditLiveInterval = setInterval(() => {
            applyAuditFilters(true); // silent mode - no toast
        }, 5000);
        
        // Run immediately
        applyAuditFilters(true);
        
        indicator.classList.remove('hidden');
        indicator.classList.add('flex');
        toggleBtn.classList.remove('bg-slate-100', 'text-slate-700', 'hover:bg-green-100', 'hover:text-green-700', 'border-slate-300');
        toggleBtn.classList.add('bg-green-100', 'text-green-700', 'hover:bg-red-100', 'hover:text-red-700', 'border-green-300');
        toggleText.textContent = 'Disable Live';
    } else {
        // Stop live refresh
        if (auditLiveInterval) {
            clearInterval(auditLiveInterval);
            auditLiveInterval = null;
        }
        
        indicator.classList.remove('flex');
        indicator.classList.add('hidden');
        toggleBtn.classList.remove('bg-green-100', 'text-green-700', 'hover:bg-red-100', 'hover:text-red-700', 'border-green-300');
        toggleBtn.classList.add('bg-slate-100', 'text-slate-700', 'hover:bg-green-100', 'hover:text-green-700', 'border-slate-300');
        toggleText.textContent = 'Enable Live';
    }
}

// Auto-enable live refresh when audit section is loaded
<?php if ($activeSection === 'audit'): ?>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-start live refresh for real-time audit updates
    if (!auditLiveEnabled) {
        toggleAuditLiveRefresh();
    }
});
<?php endif; ?>

// ========================================
// MODERN CONFIRMATION MODAL
// ========================================

function showConfirmModal({ title, message, items = [], confirmText = 'Confirm', cancelText = 'Cancel', confirmClass = 'bg-blue-600 hover:bg-blue-700', onConfirm }) {
    // Remove any existing modal
    const existingModal = document.getElementById('customConfirmModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal HTML
    const itemsList = items.map(item => `<div class="flex items-center gap-2 text-gray-700"><span class="text-green-600">✓</span> ${item}</div>`).join('');
    
    const modalHTML = `
        <div id="customConfirmModal" class="fixed inset-0 z-50 flex items-center justify-center animate-fadeIn" style="animation: fadeIn 0.2s ease-out;">
            <div class="absolute inset-0 bg-black bg-opacity-50 backdrop-blur-sm" onclick="closeConfirmModal()"></div>
            <div class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 transform animate-slideUp" style="animation: slideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);">
                <div class="p-6">
                    <h3 class="text-xl font-semibold text-gray-900 mb-3">${title}</h3>
                    ${message ? `<p class="text-gray-600 mb-3">${message}</p>` : ''}
                    ${items.length ? `<div class="space-y-2 mb-6">${itemsList}</div>` : ''}
                    <div class="flex gap-3">
                        <button onclick="confirmModalAction()" class="${confirmClass} text-white px-6 py-2.5 rounded-lg font-medium transition-all transform hover:scale-105 active:scale-95 flex-1">
                            ${confirmText}
                        </button>
                        <button onclick="closeConfirmModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2.5 rounded-lg font-medium transition-all transform hover:scale-105 active:scale-95 flex-1">
                            ${cancelText}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <style>
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes slideUp {
                from { transform: translateY(20px) scale(0.95); opacity: 0; }
                to { transform: translateY(0) scale(1); opacity: 1; }
            }
        </style>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Store callback
    window._confirmModalCallback = onConfirm;
}

function closeConfirmModal() {
    const modal = document.getElementById('customConfirmModal');
    if (modal) {
        modal.style.animation = 'fadeOut 0.2s ease-out';
        setTimeout(() => modal.remove(), 200);
    }
    delete window._confirmModalCallback;
}

function confirmModalAction() {
    const callback = window._confirmModalCallback;
    closeConfirmModal();
    if (callback && typeof callback === 'function') {
        callback();
    }
}

// ========================================
// TOAST NOTIFICATIONS
// ========================================

function showToast(message, type = 'info') {
    const colors = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        warning: 'bg-amber-500',
        info: 'bg-blue-500'
    };
    
    const icons = {
        success: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>',
        error: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>',
        warning: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>',
        info: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    };
    
    const bgColor = colors[type] || colors.info;
    const icon = icons[type] || icons.info;
    
    const toastHTML = `
        <div class="toast ${bgColor} text-white px-6 py-4 rounded-lg shadow-lg flex items-center gap-3 min-w-[300px] max-w-md transform transition-all duration-300 animate-slideInRight" style="animation: slideInRight 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                ${icon}
            </svg>
            <span class="flex-1">${message}</span>
            <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200 transition-colors">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
        </div>
        <style>
            @keyframes slideInRight {
                from { transform: translateX(400px); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        </style>
    `;
    
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'fixed top-4 right-4 space-y-2 z-50';
        document.body.appendChild(container);
    }
    
    const toastElement = document.createElement('div');
    toastElement.innerHTML = toastHTML;
    const toast = toastElement.firstElementChild;
    container.appendChild(toast);
    
    // Auto-remove after 4 seconds
    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease-out';
        toast.style.transform = 'translateX(400px)';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}
</script>

<!-- Face Registration Modal -->
<div id="faceRegModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50" style="display:none;">
    <div class="bg-white rounded-xl p-6 max-w-2xl w-full mx-4 fade-in max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-xl font-semibold text-slate-800">Register Face</h3>
                <p class="text-slate-600 text-sm" id="faceRegStudentName"></p>
            </div>
            <button onclick="closeFaceRegModal()" class="text-slate-400 hover:text-slate-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Status indicator -->
        <div id="faceRegStatus" class="bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4">
            Initializing camera...
        </div>

        <!-- Webcam container -->
        <div class="relative mx-auto mb-4 bg-black rounded-lg overflow-hidden" style="max-width:640px;">
            <video id="faceRegVideo" class="w-full" autoplay muted playsinline></video>
            <canvas id="faceRegCanvas" class="absolute top-0 left-0 w-full h-full pointer-events-none"></canvas>
        </div>

        <!-- Angle selector -->
        <div class="mb-4">
            <label class="block text-sm font-medium text-slate-700 mb-2">Face Angle</label>
            <div class="flex gap-2">
                <button onclick="selectFaceLabel('front')" id="lblFront" class="px-4 py-2 rounded-lg text-sm font-medium bg-[#0056b3] text-white">Front</button>
                <button onclick="selectFaceLabel('left')" id="lblLeft" class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-100 text-slate-600 hover:bg-slate-200">Left</button>
                <button onclick="selectFaceLabel('right')" id="lblRight" class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-100 text-slate-600 hover:bg-slate-200">Right</button>
                <button onclick="selectFaceLabel('up')" id="lblUp" class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-100 text-slate-600 hover:bg-slate-200">Up</button>
                <button onclick="selectFaceLabel('down')" id="lblDown" class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-100 text-slate-600 hover:bg-slate-200">Down</button>
            </div>
        </div>

        <!-- Capture controls -->
        <div class="flex gap-3">
            <button onclick="captureFace()" id="btnCaptureFace" class="flex-1 px-4 py-3 bg-green-500 hover:bg-green-600 text-white rounded-lg font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                📷 Capture & Register
            </button>
            <button onclick="closeFaceRegModal()" class="px-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium transition-colors">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Face Enroll Modal -->
<div id="faceEnrollModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50" style="display:none;">
    <div class="bg-white rounded-xl p-6 max-w-2xl w-full mx-4 fade-in max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-xl font-semibold text-slate-800">Enroll Student Face</h3>
                <p class="text-slate-600 text-sm" id="faceEnrollStudentName"></p>
            </div>
            <button onclick="closeFaceEnrollModal()" class="text-slate-400 hover:text-slate-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Progress Steps -->
        <div class="flex items-center justify-center gap-2 mb-4">
            <div id="enrollStep1" class="flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-[#0056b3] text-white">
                <span>1</span> Front
            </div>
            <div class="w-4 h-0.5 bg-slate-300"></div>
            <div id="enrollStep2" class="flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-slate-200 text-slate-500">
                <span>2</span> Left
            </div>
            <div class="w-4 h-0.5 bg-slate-300"></div>
            <div id="enrollStep3" class="flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-slate-200 text-slate-500">
                <span>3</span> Right
            </div>
        </div>

        <!-- Status indicator -->
        <div id="faceEnrollStatus" class="bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4">
            Initializing camera...
        </div>

        <!-- Captured faces preview -->
        <div id="enrollCapturedPreview" class="hidden mb-4">
            <p class="text-xs font-medium text-slate-500 mb-2">Captured Angles:</p>
            <div class="flex gap-2" id="enrollCapturedThumbs"></div>
        </div>

        <!-- Webcam container -->
        <div class="relative mx-auto mb-4 bg-black rounded-lg overflow-hidden" style="max-width:640px;">
            <video id="faceEnrollVideo" class="w-full" autoplay muted playsinline></video>
            <canvas id="faceEnrollCanvas" class="absolute top-0 left-0 w-full h-full pointer-events-none"></canvas>
        </div>

        <!-- Instruction -->
        <div id="enrollInstruction" class="text-center mb-4">
            <p class="text-slate-700 font-medium" id="enrollAngleInstruction">Position the student facing the camera <strong>directly (front)</strong></p>
        </div>

        <!-- Capture controls -->
        <div class="flex gap-3">
            <button onclick="captureEnrollFace()" id="btnEnrollCapture" class="flex-1 px-4 py-3 bg-[#0056b3] hover:bg-blue-700 text-white rounded-lg font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                📷 Capture Front View
            </button>
            <button onclick="closeFaceEnrollModal()" class="px-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>

<?php if (filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)): ?>
<!-- Face Recognition Scripts (Admin) -->
<script defer src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script defer src="../assets/js/face-recognition.js"></script>
<script>
// Face Registration Logic for Admin
const CSRF_TOKEN_FACE = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
let faceRegSystem = null;
let faceRegStudentId = null;
let faceRegLabel = 'front';
let faceRegModelsLoaded = false;

function selectFaceLabel(label) {
    faceRegLabel = label;
    ['front','left','right','up','down'].forEach(l => {
        const btn = document.getElementById('lbl' + l.charAt(0).toUpperCase() + l.slice(1));
        if (btn) {
            btn.className = l === label 
                ? 'px-4 py-2 rounded-lg text-sm font-medium bg-[#0056b3] text-white'
                : 'px-4 py-2 rounded-lg text-sm font-medium bg-slate-100 text-slate-600 hover:bg-slate-200';
        }
    });
}

async function openFaceRegModal(studentId, studentName, studentSid) {
    faceRegStudentId = studentId;
    document.getElementById('faceRegStudentName').textContent = studentName + ' (' + studentSid + ')';
    
    const modal = document.getElementById('faceRegModal');
    modal.style.display = 'flex';
    modal.classList.remove('hidden');
    
    const statusEl = document.getElementById('faceRegStatus');
    const captureBtn = document.getElementById('btnCaptureFace');
    captureBtn.disabled = true;

    // Initialize system if not yet done
    if (!faceRegSystem) {
        faceRegSystem = new FaceRecognitionSystem({
            modelPath: '../assets/models',
            minConfidence: 0.5,
            csrfToken: CSRF_TOKEN_FACE,
            onStatusChange: (s, msg) => { statusEl.textContent = msg; },
            onError: (msg) => { statusEl.textContent = '❌ ' + msg; statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4'; }
        });
    }

    // Load models if not loaded
    if (!faceRegModelsLoaded) {
        statusEl.textContent = 'Loading face recognition models (first time may take a moment)...';
        statusEl.className = 'bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4';
        const ok = await faceRegSystem.loadModels();
        if (!ok) {
            statusEl.textContent = '❌ Failed to load models. Ensure model files are in assets/models/';
            statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
            return;
        }
        faceRegModelsLoaded = true;
    }

    // Start camera
    statusEl.textContent = 'Starting camera...';
    const camOk = await faceRegSystem.startCamera(
        document.getElementById('faceRegVideo'),
        document.getElementById('faceRegCanvas')
    );

    if (camOk) {
        statusEl.textContent = '✅ Camera ready. Position the student\'s face in the frame and click Capture.';
        statusEl.className = 'bg-green-50 text-green-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        
        // Start live face detection preview
        startLiveDetectionPreview();
    } else {
        statusEl.textContent = '❌ Camera failed. Check browser permissions.';
        statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
    }
}

let liveDetectionTimer = null;
function startLiveDetectionPreview() {
    if (liveDetectionTimer) clearInterval(liveDetectionTimer);
    liveDetectionTimer = setInterval(async () => {
        if (!faceRegSystem) return;
        await faceRegSystem.detectSingleFace();
    }, 300);
}

function closeFaceRegModal() {
    if (liveDetectionTimer) {
        clearInterval(liveDetectionTimer);
        liveDetectionTimer = null;
    }
    if (faceRegSystem) {
        faceRegSystem.stopCamera();
    }
    const modal = document.getElementById('faceRegModal');
    modal.style.display = 'none';
    modal.classList.add('hidden');
}

async function captureFace() {
    if (!faceRegSystem || !faceRegStudentId) return;
    
    const statusEl = document.getElementById('faceRegStatus');
    const captureBtn = document.getElementById('btnCaptureFace');
    captureBtn.disabled = true;
    
    statusEl.textContent = '📸 Capturing face...';
    statusEl.className = 'bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4';
    
    // Detect face
    const detection = await faceRegSystem.detectSingleFace();
    
    if (!detection) {
        statusEl.textContent = '❌ No face detected. Please position the face clearly in the frame.';
        statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        return;
    }
    
    if (detection.score < 0.5) {
        statusEl.textContent = '⚠️ Low confidence (' + (detection.score * 100).toFixed(1) + '%). Try better lighting or positioning.';
        statusEl.className = 'bg-yellow-50 text-yellow-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        return;
    }
    
    // Register the descriptor
    statusEl.textContent = '⬆️ Registering face descriptor (' + faceRegLabel + ')...';
    
    const result = await faceRegSystem.registerFace(
        faceRegStudentId,
        detection.descriptor,
        faceRegLabel,
        detection.score,
        '../api/register_face.php'
    );
    
    if (result.success) {
        statusEl.textContent = '✅ ' + result.message + ' (Total: ' + result.total_descriptors + ')';
        statusEl.className = 'bg-green-50 text-green-700 text-sm p-3 rounded-lg mb-4';
        
        // Show toast notification
        if (typeof showToast === 'function') {
            showToast(result.message, 'success');
        }
        
        // Re-enable after 2 sec for next capture
        setTimeout(() => { captureBtn.disabled = false; }, 2000);
    } else {
        statusEl.textContent = '❌ ' + (result.error || 'Registration failed');
        statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
    }
}

async function deleteFaceData(studentId, studentName) {
    if (!confirm('Delete all face recognition data for ' + studentName + '? This cannot be undone.')) {
        return;
    }
    
    try {
        const response = await fetch('../api/delete_face.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN_FACE
            },
            credentials: 'same-origin',
            body: JSON.stringify({ student_id: studentId })
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (typeof showToast === 'function') {
                showToast(data.message, 'success');
            }
            // Reload page to refresh table
            setTimeout(() => window.location.reload(), 1000);
        } else {
            alert('Error: ' + (data.error || 'Failed to delete'));
        }
    } catch (error) {
        alert('Network error: ' + error.message);
    }
}

function filterFaceStudents() {
    const filter = document.getElementById('faceSearchInput').value.toLowerCase();
    const rows = document.querySelectorAll('.face-student-row');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
}

// ================================================================
// FACE ENROLLMENT (face_enroll section)
// ================================================================
let faceEnrollSystem = null;
let faceEnrollStudentId = null;
let faceEnrollModelsLoaded = false;
let enrollLiveTimer = null;
const enrollAngles = ['front', 'left', 'right'];
let enrollCurrentStep = 0; // 0=front, 1=left, 2=right
let enrollCapturedCount = 0;

function filterEnrollStudents() {
    const filter = (document.getElementById('enrollSearchInput')?.value || '').toLowerCase();
    const cards = document.querySelectorAll('.enroll-student-card');
    cards.forEach(card => {
        const searchText = card.getAttribute('data-search') || '';
        card.style.display = searchText.includes(filter) ? '' : 'none';
    });
}

async function openFaceEnrollModal(studentId, studentName, studentSid) {
    faceEnrollStudentId = studentId;
    enrollCurrentStep = 0;
    enrollCapturedCount = 0;
    
    document.getElementById('faceEnrollStudentName').textContent = studentName + ' (' + studentSid + ')';
    document.getElementById('enrollCapturedPreview').classList.add('hidden');
    document.getElementById('enrollCapturedThumbs').innerHTML = '';
    updateEnrollSteps(0);
    updateEnrollInstruction('front');
    
    const modal = document.getElementById('faceEnrollModal');
    modal.style.display = 'flex';
    modal.classList.remove('hidden');
    
    const statusEl = document.getElementById('faceEnrollStatus');
    const captureBtn = document.getElementById('btnEnrollCapture');
    captureBtn.disabled = true;
    captureBtn.textContent = '📷 Capture Front View';

    // Initialize face system (reuse faceRegSystem if already loaded)
    if (!faceRegSystem) {
        faceRegSystem = new FaceRecognitionSystem({
            modelPath: '../assets/models',
            minConfidence: 0.5,
            csrfToken: CSRF_TOKEN_FACE,
            onStatusChange: (s, msg) => { statusEl.textContent = msg; },
            onError: (msg) => { statusEl.textContent = '❌ ' + msg; statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4'; }
        });
    }

    if (!faceRegModelsLoaded) {
        statusEl.textContent = 'Loading face recognition models (first time may take a moment)...';
        statusEl.className = 'bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4';
        const ok = await faceRegSystem.loadModels();
        if (!ok) {
            statusEl.textContent = '❌ Failed to load models. Ensure model files are in assets/models/';
            statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
            return;
        }
        faceRegModelsLoaded = true;
    }

    statusEl.textContent = 'Starting camera...';
    const camOk = await faceRegSystem.startCamera(
        document.getElementById('faceEnrollVideo'),
        document.getElementById('faceEnrollCanvas')
    );

    if (camOk) {
        statusEl.textContent = '✅ Camera ready. Position the student\'s face FRONT and click Capture.';
        statusEl.className = 'bg-green-50 text-green-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        startEnrollLivePreview();
    } else {
        statusEl.textContent = '❌ Camera failed. Check browser permissions.';
        statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
    }
}

function startEnrollLivePreview() {
    if (enrollLiveTimer) clearInterval(enrollLiveTimer);
    enrollLiveTimer = setInterval(async () => {
        if (!faceRegSystem) return;
        await faceRegSystem.detectSingleFace();
    }, 300);
}

function closeFaceEnrollModal() {
    if (enrollLiveTimer) { clearInterval(enrollLiveTimer); enrollLiveTimer = null; }
    if (faceRegSystem) { faceRegSystem.stopCamera(); }
    const modal = document.getElementById('faceEnrollModal');
    modal.style.display = 'none';
    modal.classList.add('hidden');
}

function updateEnrollSteps(activeIndex) {
    for (let i = 0; i < 3; i++) {
        const el = document.getElementById('enrollStep' + (i + 1));
        if (i < activeIndex) {
            el.className = 'flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-green-500 text-white';
        } else if (i === activeIndex) {
            el.className = 'flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-[#0056b3] text-white';
        } else {
            el.className = 'flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-slate-200 text-slate-500';
        }
    }
}

function updateEnrollInstruction(angle) {
    const instructions = {
        'front': 'Position the student facing the camera <strong>directly (front)</strong>',
        'left': 'Ask the student to turn their head slightly to the <strong>left</strong>',
        'right': 'Ask the student to turn their head slightly to the <strong>right</strong>'
    };
    document.getElementById('enrollAngleInstruction').innerHTML = instructions[angle] || '';
    
    const captureBtn = document.getElementById('btnEnrollCapture');
    captureBtn.textContent = '📷 Capture ' + angle.charAt(0).toUpperCase() + angle.slice(1) + ' View';
}

async function captureEnrollFace() {
    if (!faceRegSystem || !faceEnrollStudentId) return;
    
    const statusEl = document.getElementById('faceEnrollStatus');
    const captureBtn = document.getElementById('btnEnrollCapture');
    captureBtn.disabled = true;
    
    const currentAngle = enrollAngles[enrollCurrentStep];
    statusEl.textContent = '📸 Capturing ' + currentAngle + ' view...';
    statusEl.className = 'bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4';
    
    const detection = await faceRegSystem.detectSingleFace();
    
    if (!detection) {
        statusEl.textContent = '❌ No face detected. Ensure the face is clearly visible.';
        statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        return;
    }
    
    if (detection.score < 0.5) {
        statusEl.textContent = '⚠️ Low confidence (' + (detection.score * 100).toFixed(1) + '%). Try better lighting.';
        statusEl.className = 'bg-yellow-50 text-yellow-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        return;
    }
    
    // Register this angle
    statusEl.textContent = '⬆️ Saving ' + currentAngle + ' face descriptor...';
    
    const result = await faceRegSystem.registerFace(
        faceEnrollStudentId,
        detection.descriptor,
        currentAngle,
        detection.score,
        '../api/register_face.php'
    );
    
    if (!result.success) {
        statusEl.textContent = '❌ ' + (result.error || 'Failed to save face.');
        statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        return;
    }
    
    // Success for this angle - add thumbnail
    enrollCapturedCount++;
    const thumbContainer = document.getElementById('enrollCapturedThumbs');
    const preview = document.getElementById('enrollCapturedPreview');
    preview.classList.remove('hidden');
    
    // Capture thumbnail from video
    const video = document.getElementById('faceEnrollVideo');
    const thumbCanvas = document.createElement('canvas');
    thumbCanvas.width = 60;
    thumbCanvas.height = 60;
    const ctx = thumbCanvas.getContext('2d');
    const size = Math.min(video.videoWidth, video.videoHeight);
    const sx = (video.videoWidth - size) / 2;
    const sy = (video.videoHeight - size) / 2;
    ctx.drawImage(video, sx, sy, size, size, 0, 0, 60, 60);
    
    thumbContainer.innerHTML += `
        <div class="text-center">
            <img src="${thumbCanvas.toDataURL('image/jpeg', 0.7)}" class="w-14 h-14 rounded-lg object-cover border-2 border-green-400">
            <p class="text-xs text-green-600 mt-1">✅ ${currentAngle}</p>
        </div>
    `;
    
    // Move to next step
    enrollCurrentStep++;
    
    if (enrollCurrentStep >= enrollAngles.length) {
        // All 3 angles captured - enrollment complete!
        if (enrollLiveTimer) { clearInterval(enrollLiveTimer); enrollLiveTimer = null; }
        
        statusEl.textContent = '🎉 Face enrollment complete! All 3 angles captured successfully.';
        statusEl.className = 'bg-green-50 text-green-700 text-sm p-3 rounded-lg mb-4 font-medium';
        
        updateEnrollSteps(3); // All green
        
        captureBtn.textContent = '✅ Enrollment Complete';
        captureBtn.disabled = true;
        captureBtn.className = 'flex-1 px-4 py-3 bg-green-500 text-white rounded-lg font-medium cursor-default';
        
        showToast('Face enrolled successfully for ' + document.getElementById('faceEnrollStudentName').textContent, 'success');
        
        // Remove the student card from the grid with animation
        const card = document.querySelector(`.enroll-student-card button[onclick*="openFaceEnrollModal(${faceEnrollStudentId}"]`);
        if (card) {
            const cardEl = card.closest('.enroll-student-card');
            if (cardEl) {
                cardEl.style.transition = 'all 0.5s ease';
                cardEl.style.opacity = '0';
                cardEl.style.transform = 'scale(0.95)';
                setTimeout(() => cardEl.remove(), 500);
            }
        }
        
        // Close modal after delay and reload to update counts
        setTimeout(() => {
            closeFaceEnrollModal();
            window.location.reload();
        }, 2500);
    } else {
        // Move to next angle
        const nextAngle = enrollAngles[enrollCurrentStep];
        updateEnrollSteps(enrollCurrentStep);
        updateEnrollInstruction(nextAngle);
        
        statusEl.textContent = '✅ ' + currentAngle + ' captured! Now position for ' + nextAngle + ' view.';
        statusEl.className = 'bg-green-50 text-green-700 text-sm p-3 rounded-lg mb-4';
        
        captureBtn.disabled = false;
    }
}
</script>
<?php endif; ?>

</body>
</html>
