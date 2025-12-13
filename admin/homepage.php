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
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        $_SESSION['admin_id'] = $admin['id'] ?? 1;
    } catch (PDOException $e) {
        error_log("Auto-fix admin_id error: " . $e->getMessage());
        $_SESSION['admin_id'] = 1; // Fallback
    }
}

$page_title = 'Student Management';

// Get all students
try {
    $pdo = pdo();
    
    // Get all students WITHOUT registered RFID cards (for Student Management panel)
    // Exclude students pending verification
    $query = '
        SELECT id, student_id, name, email, status, role, rfid_uid, rfid_registered_at, profile_picture
        FROM users 
        WHERE role = "Student" AND rfid_uid IS NULL AND verification_status = "approved"
        ORDER BY created_at DESC
    ';
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get ALL students including those with RFID (for Registered Cards panel)
    // Exclude students pending verification
    $queryAll = '
        SELECT id, student_id, name, email, status, role, rfid_uid, rfid_registered_at, profile_picture
        FROM users 
        WHERE role = "Student" AND verification_status = "approved"
        ORDER BY created_at DESC
    ';
    $stmtAll = $pdo->prepare($queryAll);
    $stmtAll->execute();
    $allStudents = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    
    // Get registered cards count
    $stmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "Student" AND rfid_uid IS NOT NULL');
    $registeredCount = $stmt->fetchColumn();
    
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
        $missingCards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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
                } catch (PDOException $e) {
                    // Skip duplicates or conflicts
                    error_log("Skipping rfid_cards insert for user {$card['id']}: " . $e->getMessage());
                }
            }
        }
    } catch (PDOException $e) {
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
    $violationAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    
    // Get pending verification students
    $stmt = $pdo->query('
        SELECT id, student_id, name, email, created_at, profile_picture 
        FROM users 
        WHERE role = "Student" AND verification_status = "pending"
        ORDER BY created_at DESC
    ');
    $pendingStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingCount = count($pendingStudents);
    
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    $error = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    error_log('Error fetching students: ' . $e->getMessage());
    $error = 'Failed to load student data: ' . $e->getMessage();
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
                                    SELECT rc.id AS card_id, rc.is_lost, rc.lost_at, rc.lost_reason, rc.status,
                                           admin.name AS reported_by_name
                                    FROM rfid_cards rc
                                    LEFT JOIN users admin ON rc.lost_reported_by = admin.id
                                    WHERE rc.user_id = ?
                                    LIMIT 1
                                ");
                                $cardStmt->execute([$student['id']]);
                                $cardInfo = $cardStmt->fetch();
                                
                                // If card not found in rfid_cards table, create it now
                                if (!$cardInfo && !empty($student['rfid_uid'])) {
                                    $insertStmt = $pdo->prepare("
                                        INSERT INTO rfid_cards (user_id, rfid_uid, registered_at, status)
                                        VALUES (?, ?, ?, 'active')
                                    ");
                                    $insertStmt->execute([
                                        $student['id'],
                                        $student['rfid_uid'],
                                        $student['rfid_registered_at'] ?? date('Y-m-d H:i:s')
                                    ]);
                                    
                                    // Fetch the newly created card
                                    $cardStmt->execute([$student['id']]);
                                    $cardInfo = $cardStmt->fetch();
                                }
                                
                                $isLost = $cardInfo && $cardInfo['is_lost'] == 1;
                            } catch (PDOException $e) {
                                // If rfid_cards table doesn't exist or query fails, use basic info from users table
                                error_log("RFID card query error: " . $e->getMessage());
                                $cardInfo = ['card_id' => $student['id'], 'is_lost' => 0]; // Use user_id as fallback
                                $isLost = false;
                            }
                        ?>
                            <div class="border <?php echo $isLost ? 'border-red-300 bg-red-50/50' : 'border-green-200 bg-green-50/50'; ?> rounded-lg p-4 fade-in">
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
                                            onclick="openEditStudentModal('<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['student_id']); ?>', '<?php echo htmlspecialchars($student['email']); ?>', '<?php echo htmlspecialchars($student['profile_picture'] ?? ''); ?>')"
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
                                                onclick="toggleRfidLostStatus(<?php echo $cardInfo['card_id']; ?>, '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['email']); ?>', false)"
                                                class="px-4 py-2 bg-green-600 text-white rounded-lg btn-hover text-sm whitespace-nowrap flex items-center gap-2"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                                Disable Mark Lost ID
                                            </button>
                                        <?php else: ?>
                                            <button 
                                                onclick="toggleRfidLostStatus(<?php echo $cardInfo['card_id']; ?>, '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['email']); ?>', true)"
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
                            $recentViolations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
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
                    } catch (Exception $e) {
                        echo '<p class="text-red-600 text-center py-8">Error loading violations: ' . htmlspecialchars($e->getMessage()) . '</p>';
                    }
                    ?>
                </div>
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
    <div class="bg-white rounded-xl p-8 max-w-2xl w-full mx-4 fade-in max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-semibold text-slate-800">Edit Student Information</h3>
            <button onclick="closeEditStudentModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <div id="editStudentForm">
            <input type="hidden" id="editStudentUserId" />
            
            <!-- Current Profile Picture -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 mb-2">Current Profile Picture</label>
                <div class="flex items-center gap-4">
                    <img id="currentProfilePicture" src="../assets/images/avatars/default-avatar.png" 
                         alt="Profile" class="w-20 h-20 rounded-full object-cover border-2 border-slate-200">
                    <button type="button" id="deleteProfilePictureBtn" onclick="deleteStudentProfilePicture()" 
                            class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 text-sm">
                        Delete Picture
                    </button>
                </div>
            </div>
            
            <!-- Upload New Profile Picture -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 mb-2">Upload New Profile Picture</label>
                <form action="upload_student_picture.php" class="dropzone" id="studentPictureDropzone">
                    <input type="hidden" id="dropzoneStudentId" name="student_id" />
                </form>
            </div>
            
            <!-- Student Information -->
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Full Name</label>
                    <input type="text" id="editStudentName" 
                           class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Student ID</label>
                    <input type="text" id="editStudentId" 
                           class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none">
                    <p class="text-xs text-slate-500 mt-1">For temp IDs (e.g., TEMP-1702425600), enter the real student ID here</p>
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
            messageEl.textContent = message;
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
        cancelBtn.textContent = options.cancelText || 'Cancel';
        
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
        cancelBtn.onclick = handleCancel;
        
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
async function toggleRfidLostStatus(cardId, studentName, studentEmail, markAsLost) {
    console.log('toggleRfidLostStatus called:', { cardId, studentName, studentEmail, markAsLost });
    
    if (!cardId) {
        showToast('Card ID is missing. Please refresh the page and try again.', 'error');
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
    if (confirm('Are you sure you want to delete this account?')) {
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
                location.reload();
            } else {
                alert(data.error || 'Failed to delete account');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to delete account');
        });
    }
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
    if (!confirm(`Approve account for ${studentName}?\n\nThis will:\n✓ Activate their account\n✓ Send approval email\n✓ Allow them to log in`)) {
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
            action: 'approve'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message with animation
            btn.innerHTML = '<svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
            btn.classList.remove('bg-green-600', 'hover:bg-green-700');
            btn.classList.add('bg-green-800');
            
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            alert(data.error || 'Failed to approve account');
            btn.disabled = false;
            btn.innerHTML = originalContent;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to approve account. Please try again.');
        btn.disabled = false;
        btn.innerHTML = originalContent;
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

let studentPictureDropzone = null;

function openEditStudentModal(userId, name, studentId, email, profilePicture) {
    // Set form values
    document.getElementById('editStudentUserId').value = userId;
    document.getElementById('editStudentName').value = name;
    document.getElementById('editStudentId').value = studentId;
    document.getElementById('editStudentEmail').value = email;
    document.getElementById('dropzoneStudentId').value = userId;
    
    // Set profile picture
    const imgElement = document.getElementById('currentProfilePicture');
    if (profilePicture && profilePicture !== '') {
        imgElement.src = '../assets/images/profiles/' + profilePicture;
        document.getElementById('deleteProfilePictureBtn').style.display = 'block';
    } else {
        imgElement.src = '../assets/images/avatars/default-avatar.png';
        document.getElementById('deleteProfilePictureBtn').style.display = 'none';
    }
    
    // Show modal
    document.getElementById('editStudentModal').classList.remove('hidden');
    document.getElementById('editStudentModal').classList.add('flex');
    
    // Initialize Dropzone if not already initialized
    if (!studentPictureDropzone) {
        initializeStudentDropzone();
    }
}

function closeEditStudentModal() {
    document.getElementById('editStudentModal').classList.add('hidden');
    document.getElementById('editStudentModal').classList.remove('flex');
    
    // Remove all files from dropzone
    if (studentPictureDropzone) {
        studentPictureDropzone.removeAllFiles();
    }
}

function initializeStudentDropzone() {
    // Disable auto-discover
    Dropzone.autoDiscover = false;
    
    studentPictureDropzone = new Dropzone("#studentPictureDropzone", {
        url: "upload_student_picture.php",
        maxFiles: 1,
        maxFilesize: 5,
        acceptedFiles: "image/jpeg,image/png,image/jpg",
        addRemoveLinks: true,
        createImageThumbnails: false,
        previewsContainer: false,
        dictDefaultMessage: "Drop student photo here or click to upload",
        dictRemoveFile: "Remove",
        dictFileTooBig: "File is too big ({{filesize}}MB). Max filesize: {{maxFilesize}}MB.",
        dictInvalidFileType: "Invalid file type. Only JPG and PNG are allowed.",
        init: function() {
            this.on("sending", function(file, xhr, formData) {
                formData.append("student_id", document.getElementById('dropzoneStudentId').value);
            });
            
            this.on("success", function(file, response) {
                try {
                    const data = typeof response === 'string' ? JSON.parse(response) : response;
                    
                    if (data.success) {
                        // Update the current profile picture
                        document.getElementById('currentProfilePicture').src = '../assets/images/profiles/' + data.filename;
                        document.getElementById('deleteProfilePictureBtn').style.display = 'block';
                        
                        // Show success message
                        alert('Profile picture uploaded successfully!');
                        
                        // Remove the file from dropzone
                        this.removeFile(file);
                        
                        // Reload page after short delay to update all student info displays
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        alert('Upload failed: ' + (data.error || 'Unknown error'));
                        this.removeFile(file);
                    }
                } catch (e) {
                    console.error('Error parsing response:', e);
                    alert('Upload failed. Please try again.');
                    this.removeFile(file);
                }
            });
            
            this.on("error", function(file, errorMessage) {
                console.error('Dropzone error:', errorMessage);
                let errorText = 'Unknown error';
                
                if (typeof errorMessage === 'string') {
                    errorText = errorMessage;
                } else if (errorMessage && errorMessage.error) {
                    errorText = errorMessage.error;
                } else if (errorMessage && errorMessage.message) {
                    errorText = errorMessage.message;
                }
                
                alert('Upload error: ' + errorText);
                this.removeFile(file);
            });
            
            this.on("maxfilesexceeded", function(file) {
                this.removeAllFiles();
                this.addFile(file);
            });
        }
    });
}

async function saveStudentInfo() {
    const userId = document.getElementById('editStudentUserId').value;
    const name = document.getElementById('editStudentName').value.trim();
    const studentId = document.getElementById('editStudentId').value.trim();
    
    // Validation
    if (!name) {
        alert('Please enter student name');
        return;
    }
    
    if (!studentId) {
        alert('Please enter student ID');
        return;
    }
    
    if (studentId.length < 3) {
        alert('Student ID must be at least 3 characters');
        return;
    }
    
    try {
        const response = await fetch('update_student_info.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                user_id: userId,
                name: name,
                student_id: studentId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Student information updated successfully!');
            closeEditStudentModal();
            location.reload(); // Reload to show updated info
        } else {
            // Check if it's just "no changes" error - this is OK if only picture was uploaded
            if (data.error && data.error.includes('no changes made')) {
                alert('Information saved. No changes were made to name or student ID.');
                closeEditStudentModal();
                location.reload(); // Still reload to show any picture updates
            } else {
                alert('Update failed: ' + (data.error || 'Unknown error'));
            }
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to update student information. Please try again.');
    }
}

async function deleteStudentProfilePicture() {
    const userId = document.getElementById('editStudentUserId').value;
    
    if (!confirm('Are you sure you want to delete this student\'s profile picture?')) {
        return;
    }
    
    try {
        const response = await fetch('delete_student_picture.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                user_id: userId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Reset to default avatar
            document.getElementById('currentProfilePicture').src = '../assets/images/avatars/default-avatar.png';
            document.getElementById('deleteProfilePictureBtn').style.display = 'none';
            alert('Profile picture deleted successfully!');
        } else {
            alert('Delete failed: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to delete profile picture. Please try again.');
    }
}
</script>

</body>
</html>
