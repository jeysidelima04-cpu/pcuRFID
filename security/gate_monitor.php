<?php

require_once __DIR__ . '/../db.php';

// ============================================
// SECURITY CONFIGURATION
// ============================================

// Configure secure session settings (if not already set in php.ini)
if (session_status() === PHP_SESSION_ACTIVE) {
    // Set secure session cookie parameters
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    
    ini_set('session.cookie_httponly', '1');  // Prevent JavaScript access to session cookie
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');  // Send cookie only over HTTPS (in production)
    ini_set('session.cookie_samesite', 'Strict');  // CSRF protection
    ini_set('session.use_strict_mode', '1');  // Reject uninitialized session IDs
    ini_set('session.use_only_cookies', '1');  // Prevent session fixation via URL
}

// Security Headers - Protect against common web vulnerabilities
header('X-Frame-Options: DENY');  // Prevent clickjacking
header('X-Content-Type-Options: nosniff');  // Prevent MIME-sniffing
header('X-XSS-Protection: 1; mode=block');  // Enable XSS filter (legacy browsers)
header('Referrer-Policy: strict-origin-when-cross-origin');  // Control referrer information

// Content Security Policy - Allow only trusted sources
$csp = "default-src 'self'; ";
$csp .= "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net; ";
$csp .= "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; ";
$csp .= "img-src 'self' data: https:; ";
$csp .= "font-src 'self' data:; ";
$csp .= "connect-src 'self'; ";
$csp .= "frame-ancestors 'none';";
header("Content-Security-Policy: " . $csp);

// Session timeout configuration (30 minutes of inactivity)
define('SESSION_TIMEOUT', 1800); // 30 minutes

// Check if security guard is logged in
if (!isset($_SESSION['security_logged_in'])) {
    header('Location: security_login.php');
    exit;
}

// Session timeout check - logout if inactive for too long
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    // Session expired due to inactivity
    session_unset();
    session_destroy();
    header('Location: security_login.php?timeout=1');
    exit;
}

// Update last activity timestamp
$_SESSION['last_activity'] = time();

// Session regeneration - regenerate session ID periodically to prevent session fixation
if (!isset($_SESSION['created_at'])) {
    $_SESSION['created_at'] = time();
} elseif (time() - $_SESSION['created_at'] > 300) { // Regenerate every 5 minutes
    session_regenerate_id(true);
    $_SESSION['created_at'] = time();
}

$guard_username = $_SESSION['security_username'] ?? 'Security Guard';

// Check if face recognition is enabled
$faceRecEnabled = filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
$faceMatchThreshold = (float)env('FACE_MATCH_THRESHOLD', '0.6');

// Permissions-Policy - Conditionally allow camera for face recognition
if ($faceRecEnabled) {
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');  // Allow camera for face recognition
} else {
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');  // Disable all unnecessary features
}

// Ensure CSRF token exists for API calls
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get today's statistics
try {
    $pdo = pdo();
    
    // Total scans today
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM violations 
        WHERE DATE(scanned_at) = CURDATE()
    ");
    $todayScans = $stmt->fetchColumn();
    
    // Unique students today
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT user_id) 
        FROM violations 
        WHERE DATE(scanned_at) = CURDATE()
    ");
    $uniqueStudents = $stmt->fetchColumn();
    
    // High violation students (3+)
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE role = 'Student' AND violation_count >= 3
    ");
    $highViolationCount = $stmt->fetchColumn();
    
    // Face entries today
    $faceEntriesToday = 0;
    if ($faceRecEnabled) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM face_entry_logs WHERE DATE(created_at) = CURDATE()");
            $faceEntriesToday = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            $faceEntriesToday = 0;
        }
    }
    
} catch (\PDOException $e) {
    error_log('Stats error: ' . $e->getMessage());
    $todayScans = 0;
    $uniqueStudents = 0;
    $highViolationCount = 0;
    $faceEntriesToday = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GateWatch Security | Gate Monitor</title>
    <link rel="icon" type="image/png" href="../assets/images/gatewatch-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/tailwind.config.js"></script>
    <script src="../assets/js/digital-id-card.js?v=11"></script>
    <link rel="preload" as="image" href="../assets/images/id-card-template.png">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <!-- Face Recognition: face-api.js (pre-trained TensorFlow.js models) -->
    <?php if ($faceRecEnabled): ?>
    <script defer src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <script defer src="../assets/js/face-recognition.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/face-recognition.js'); ?>"></script>
    <?php endif; ?>
    <style type="text/tailwindcss">
        .fade-in {
            /* instant - no animation */
        }
        .btn-hover {
            transition: all 0.3s ease;
        }
        .btn-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 86, 179, 0.1), 0 2px 4px -1px rgba(0, 86, 179, 0.06);
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.95); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.7; }
            100% { transform: scale(0.95); opacity: 1; }
        }
        .pulse-ring {
            animation: pulse-ring 2s ease-in-out infinite;
        }
    </style>
    <style>
        :root {
            --sky-50: #f0f9ff;
            --sky-100: #e0f2fe;
            --sky-600: #0284c7;
            --slate-900: #0f172a;
            --glass: rgba(255, 255, 255, 0.65);
        }

        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
        }

        body {
            background: radial-gradient(circle at 20% 20%, rgba(2, 132, 199, 0.08), transparent 30%),
                        radial-gradient(circle at 80% 0%, rgba(14, 165, 233, 0.1), transparent 28%),
                        radial-gradient(circle at 0% 80%, rgba(2, 132, 199, 0.07), transparent 25%),
                        linear-gradient(135deg, #e0f2ff 0%, #f8fbff 100%);
            background-attachment: fixed;
            overflow-x: hidden;
            color: #0f172a;
        }

        .page-shell {
            position: relative;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .hero-photo {
            position: fixed;
            inset: 0;
            background-image: url('../assets/images/pcu-building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            filter: saturate(1.05);
            opacity: 0.55;
            z-index: 0;
        }

        .hero-gradient {
            position: fixed;
            inset: 0;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.18), rgba(255, 255, 255, 0.78));
            z-index: 0;
        }

        .floating-blob {
            position: fixed;
            filter: blur(40px);
            opacity: 0.35;
            mix-blend-mode: multiply;
            z-index: 1;
            transform: scale(1.1);
            pointer-events: none;
        }

        .blob-1 {
            top: -120px;
            left: -160px;
            width: 360px;
            height: 360px;
            background: radial-gradient(circle, rgba(2, 132, 199, 0.55), rgba(2, 132, 199, 0.25));
        }

        .blob-2 {
            bottom: 0;
            right: -120px;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(14, 165, 233, 0.45), rgba(14, 165, 233, 0.18));
        }

        .glass-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.14);
        }

        .nav-blur {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .stat-card {
            position: relative;
            overflow: hidden;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.08), rgba(14, 165, 233, 0.05));
            opacity: 0;
            transition: opacity 0.25s ease;
            pointer-events: none;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 35px rgba(15, 23, 42, 0.08);
        }

        .stat-card:hover::after {
            opacity: 1;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .sidebar-button {
            transition: all 0.2s ease;
        }

        .sidebar-button:hover,
        .sidebar-button:focus {
            background-color: #0284c7;
            color: white;
        }
    </style>
</head>
<body class="text-slate-900">
    <div class="page-shell">
        <div class="hero-photo"></div>
        <div class="hero-gradient"></div>
        <span class="floating-blob blob-1"></span>
        <span class="floating-blob blob-2"></span>

        <!-- Header -->
        <nav class="nav-blur border-b border-slate-200 fixed w-full top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16 items-center">
                    <div class="flex items-center gap-3">
                        <a href="gate_monitor.php" class="flex items-center hover:opacity-90 transition-opacity">
                            <img src="../assets/images/pcu-logo.png" alt="PCU Logo" class="h-10 w-10">
                            <span class="ml-2 text-xl font-semibold text-sky-700">Philippine Christian University</span>
                        </a>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="text-right hidden sm:block">
                            <p class="text-slate-700 font-medium text-sm"><?php echo htmlspecialchars($guard_username); ?></p>
                            <p class="text-slate-400 text-xs"><?php echo date('M j, Y g:i A'); ?></p>
                        </div>
                        <a href="security_logout.php" class="sidebar-button px-4 py-2 rounded-full text-sm font-semibold text-slate-700 border border-slate-300 hover:text-white hover:bg-sky-600 focus:outline-none focus:ring-2 focus:ring-sky-500 transition-colors">
                            Sign Out
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-12 relative z-10 flex-1">
            <!-- Page Title -->
            <div class="glass-card rounded-3xl p-6 mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Gate Entrance Monitoring</h1>
                <p class="text-slate-600 mt-1">Monitor student RFID card scans and track violations in real-time</p>
            </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6 mb-6 md:mb-8">
            <!-- Today's Scans -->
            <div class="glass-card rounded-3xl p-5 stat-card">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Today's</p>
                        <h3 class="text-xl font-semibold text-slate-900">Scans</h3>
                    </div>
                    <span class="badge bg-blue-50 text-blue-700 border border-blue-100">
                        <?php echo number_format($todayScans); ?> total
                    </span>
                </div>
                <p class="text-slate-600 leading-relaxed">Total RFID card taps recorded at the gate today.</p>
            </div>

            <!-- Unique Students -->
            <div class="glass-card rounded-3xl p-5 stat-card">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Unique</p>
                        <h3 class="text-xl font-semibold text-slate-900">Students</h3>
                    </div>
                    <span class="badge bg-purple-50 text-purple-700 border border-purple-100">
                        <?php echo number_format($uniqueStudents); ?> today
                    </span>
                </div>
                <p class="text-slate-600 leading-relaxed">Distinct students who have scanned their cards today.</p>
            </div>

            <!-- High Violations -->
            <div class="glass-card rounded-3xl p-5 stat-card sm:col-span-2 lg:col-span-1">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Violations</p>
                        <h3 class="text-xl font-semibold text-slate-900">High Alert</h3>
                    </div>
                    <span class="badge bg-rose-50 text-rose-700 border border-rose-100">
                        <?php echo number_format($highViolationCount); ?> students
                    </span>
                </div>
                <p class="text-slate-600 leading-relaxed">Students with 3 or more recorded violations.</p>
            </div>
        </div>

        <!-- Main Scan Area -->
        <div class="max-w-4xl mx-auto mb-6 md:mb-8 glass-card rounded-3xl stat-card">
            <?php if ($faceRecEnabled): ?>
            <!-- Mode Selector -->
            <div class="flex gap-2 p-5 pb-0">
                <button id="btnRfidMode" onclick="switchMode('rfid')" class="flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-sky-600 text-white shadow-md">
                    <svg class="w-5 h-5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    RFID Scanner
                </button>
                <button id="btnFaceMode" onclick="switchMode('face')" class="flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-white/80 text-slate-600 border border-white/60 hover:bg-white">
                    <svg class="w-5 h-5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    Face Recognition
                </button>
            </div>
            <?php endif; ?>

            <!-- RFID Scanner Panel -->
            <div id="rfidPanel" class="overflow-hidden">
                <div class="px-6 pt-5 pb-4 border-b border-slate-200/30">
                    <p class="text-xs uppercase tracking-[0.12em] text-slate-500">RFID</p>
                    <h2 class="text-xl font-semibold text-slate-900">Scanner</h2>
                    <p class="text-sm text-slate-600 mt-1">Tap student card on reader to record entry</p>
                </div>
                <!-- Scan Status Display -->
                <div id="scanStatus" class="p-8 md:p-12 text-center min-h-[300px] md:min-h-[400px] flex items-center justify-center">
                    <div>
                        <div class="mb-6 relative inline-block">
                            <div class="pulse-ring absolute inset-0 bg-blue-400 rounded-full opacity-20"></div>
                            <svg class="w-24 h-24 md:w-32 md:h-32 text-slate-300 relative z-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                <circle cx="12" cy="12" r="3" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <h2 class="text-2xl md:text-3xl font-bold text-slate-700 mb-2">Ready to Scan</h2>
                        <p class="text-slate-500 text-sm md:text-base">Hold RFID card near scanner to verify student entry</p>
                        <p class="text-slate-400 text-xs md:text-sm mt-2">System Active • Waiting for card...</p>
                    </div>
                </div>
            </div>

            <?php if ($faceRecEnabled): ?>
            <!-- Face Recognition Panel (hidden by default) -->
            <div id="facePanel" class="overflow-hidden hidden">
                <div class="px-6 pt-5 pb-4 border-b border-slate-200/30">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Face</p>
                            <h2 class="text-xl font-semibold text-slate-900">Recognition</h2>
                            <p class="text-sm text-slate-600 mt-1" id="faceStatus">Loading models...</p>
                        </div>
                        <div class="flex gap-2">
                            <button id="btnStartFace" onclick="startFaceDetection()" class="px-3 py-2 bg-green-500 hover:bg-green-600 text-white text-sm rounded-lg transition-colors font-medium hidden">
                                &#9654; Start
                            </button>
                            <button id="btnStopFace" onclick="stopFaceDetection()" class="px-3 py-2 bg-red-500 hover:bg-red-600 text-white text-sm rounded-lg transition-colors font-medium hidden">
                                &#9632; Stop
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Webcam & Detection Area -->
                <div class="p-6 md:p-8">

                    <!-- ===== CAMERA SELECTOR — always visible ===== -->
                    <div class="mb-5 bg-white/70 border border-slate-200/70 rounded-xl p-4 backdrop-blur">
                        <div class="flex items-center gap-3 flex-wrap">
                            <svg class="w-5 h-5 text-[#0056b3] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.069A1 1 0 0121 8.882v6.236a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                            <label for="cameraSelect" class="text-sm font-semibold text-slate-700 whitespace-nowrap">Select Camera:</label>
                            <select id="cameraSelect" onchange="changeCameraDevice(this.value)"
                                class="flex-1 min-w-0 border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-[#0056b3] focus:border-[#0056b3]">
                                <option value="">&#8212; Detecting cameras... &#8212;</option>
                            </select>
                            <button onclick="populateCameraSelector()" title="Refresh camera list"
                                class="flex-shrink-0 flex items-center gap-1.5 px-3 py-2 bg-white border border-slate-300 hover:bg-slate-100 text-slate-600 text-sm rounded-lg transition-colors font-medium">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                Refresh
                            </button>
                        </div>
                        <p id="cameraSelectHint" class="text-xs text-slate-400 mt-2 ml-8">Camera list will populate once permission is granted.</p>
                    </div>
                    <!-- ===== END CAMERA SELECTOR ===== -->
                    <div id="faceInitStatus" class="text-center py-12">
                        <svg class="animate-spin w-12 h-12 mx-auto text-blue-500 mb-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p class="text-slate-600">Initializing face recognition system...</p>
                    </div>
                    
                    <div id="faceVideoContainer" class="relative mx-auto hidden" style="max-width: 640px;">
                        <video id="faceVideo" class="w-full rounded-lg shadow-inner bg-black" autoplay muted playsinline></video>
                        <canvas id="faceCanvas" class="absolute top-0 left-0 w-full h-full pointer-events-none"></canvas>
                        
                        <!-- Face match result overlay -->
                        <div id="faceMatchOverlay" class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-4 hidden">
                            <div id="faceMatchInfo" class="text-white"></div>
                        </div>
                    </div>
                    
                    <!-- Face scan result display (full screen result like RFID) -->
                    <div id="faceScanResult" class="mt-4 hidden"></div>
                    
                    <!-- Face recognition stats -->
                    <div class="flex gap-4 mt-4 text-center text-sm">
                        <div class="flex-1 bg-white/70 backdrop-blur rounded-lg p-3 border border-white/60">
                            <p class="text-slate-500">Loaded Faces</p>
                            <p id="faceLoadedCount" class="text-xl font-bold text-slate-800">0</p>
                        </div>
                        <div class="flex-1 bg-white/70 backdrop-blur rounded-lg p-3 border border-white/60">
                            <p class="text-slate-500">Matches Today</p>
                            <p class="text-xl font-bold text-slate-800"><?php echo number_format($faceEntriesToday); ?></p>
                        </div>
                        <div class="flex-1 bg-white/70 backdrop-blur rounded-lg p-3 border border-white/60">
                            <p class="text-slate-500">Threshold</p>
                            <p class="text-xl font-bold text-slate-800"><?php echo $faceMatchThreshold; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Scans Log -->
        <div class="max-w-5xl mx-auto">
            <div class="glass-card rounded-3xl overflow-hidden stat-card">
                <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-slate-200/30">
                    <div>
                        <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Activity</p>
                        <h3 class="text-xl font-semibold text-slate-900">Recent Scans</h3>
                        <p class="text-sm text-slate-600 mt-1">Live activity log</p>
                    </div>
                    <span class="badge bg-sky-50 text-sky-700 border border-sky-100 cursor-pointer hover:bg-sky-100 transition-colors" onclick="clearLog()">
                        Clear Log
                    </span>
                </div>
                <div id="scanLog" class="p-6 space-y-3 max-h-[400px] overflow-y-auto">
                    <p class="text-center text-slate-400 py-8 text-sm">No scans recorded yet. Waiting for first card tap...</p>
                </div>
            </div>
        </div>
        </main>
    </div>

    <script>
    // ================================================================
    // SECURITY & CONFIGURATION
    // ================================================================
    const CSRF_TOKEN = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
    
    let cardBuffer = '';
    let bufferTimeout = null;
    let scanLog = [];
    let scanInProgress = false;

    // XSS escape helper for safe innerHTML rendering
    function escHtml(str) {
        if (str === null || str === undefined) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    // Listen for RFID scanner (keyboard emulation) - Use keydown to capture ALL keys before processing
    document.addEventListener('keydown', function(e) {
        // If Enter key, process the buffer we've accumulated
        if (e.key === 'Enter') {
            e.preventDefault();
            const uid = cardBuffer.trim();
            if (uid.length >= 4 && !scanInProgress) {
                processRFIDScan(uid);
            }
            cardBuffer = '';
            clearTimeout(bufferTimeout);
            return;
        }
        
        // Accumulate all other single-character keys
        if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            cardBuffer += e.key;
            clearTimeout(bufferTimeout);
            bufferTimeout = setTimeout(() => {
                if (cardBuffer.length > 0 && cardBuffer.length < 4) {
                    cardBuffer = '';
                }
            }, 80);
        }
    });

    function processRFIDScan(uid) {
        scanInProgress = true;

        // No loading screen — fire immediately, display result instantly
        fetch('gate_scan.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify({rfid_uid: uid, csrf_token: CSRF_TOKEN})
        })
        .then(res => res.json())
        .then(data => {
            scanInProgress = false;
            if (data.success) {
                displaySuccessScan(data);
            } else if (data.is_lost) {
                displayLostCardScan(data, uid);
            } else if (data.access_denied) {
                displayAccessDenied(data);
            } else {
                displayErrorScan(data, uid);
            }
        })
        .catch(error => {
            scanInProgress = false;
            displayErrorScan({error: 'Network error occurred'}, uid);
        });
    }

    function displaySuccessScan(data) {
        const student = data.student;
        let severityColor = 'yellow';
        let severityBg = 'bg-yellow-50';
        let severityBorder = 'border-yellow-400';
        let severityIcon = '⚠️';
        
        if (student.severity === 'critical') {
            severityColor = 'red';
            severityBg = 'bg-red-50';
            severityBorder = 'border-red-500';
            severityIcon = '⚠️';
        } else if (student.severity === 'high') {
            severityColor = 'orange';
            severityBg = 'bg-orange-50';
            severityBorder = 'border-orange-500';
            severityIcon = '⚠️';
        } else if (student.severity === 'medium') {
            severityColor = 'yellow';
            severityBg = 'bg-yellow-50';
            severityBorder = 'border-yellow-400';
            severityIcon = '⚡';
        } else {
            severityColor = 'green';
            severityBg = 'bg-green-50';
            severityBorder = 'border-green-400';
            severityIcon = '✓';
        }
        
        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in w-full">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                    <!-- Digital ID Card -->
                    <div id="gateDigitalIdContainer" class="flex justify-center"></div>
                    
                    <!-- Violation Info -->
                    <div class="${severityBg} border-4 ${severityBorder} rounded-2xl p-6 md:p-8">
                        <div class="text-5xl md:text-6xl mb-4">${severityIcon}</div>
                        <h2 class="text-2xl md:text-3xl font-bold text-${severityColor}-800 mb-3">NO PHYSICAL ID</h2>
                        
                        <div class="bg-white rounded-lg p-4 md:p-6 mb-4">
                            <p class="text-xl md:text-2xl font-bold text-slate-800 mb-2">${escHtml(student.name)}</p>
                            <p class="text-slate-600 mb-1">ID: ${escHtml(student.student_id)}</p>
                            ${student.course ? `<p class="text-slate-500 text-sm mb-1">${escHtml(student.course)}</p>` : ''}
                            <p class="text-slate-500 text-sm">${escHtml(student.email)}</p>
                        </div>
                        <div class="bg-${severityColor}-100 rounded-lg p-4 mb-3">
                            <p class="text-${severityColor}-900 font-bold text-2xl md:text-3xl mb-1">Violation #${escHtml(String(student.violation_count))}</p>
                            <p class="text-${severityColor}-700 text-sm md:text-base">${escHtml(student.severity_message)}</p>
                        </div>
                        <p class="text-slate-600 text-sm md:text-base">${escHtml(data.message)}</p>
                        ${student.violation_count === 3 ? '<p class="text-red-600 font-bold mt-3 text-sm md:text-base animate-pulse">🚨 FINAL WARNING - Next time entry will be DENIED!</p>' : ''}
                    </div>
                </div>
            </div>
        `;

        // Render Digital ID Card in the gate monitor
        const gateIdCard = new DigitalIdCard('#gateDigitalIdContainer', {
            templateSrc: '../assets/images/id-card-template.png',
            student: {
                name: student.name || '',
                studentId: student.student_id || '',
                course: student.course || '',
                email: student.email || '',
                profilePicture: student.profile_picture 
                    ? '../assets/images/profiles/' + student.profile_picture 
                    : null
            }
        });
        gateIdCard.render();

        // Add to log
        addToScanLog(student, data.timestamp);

        // Reset after delay — long enough for guard to read both ID card and violation info
        setTimeout(resetScanDisplay, student.violation_count >= 3 ? 7000 : 5000);
    }

    function displayLostCardScan(data, uid) {
        const student = data.student || {};
        const lostInfo = data.lost_info || {};
        const lostDate = lostInfo.lost_at ? new Date(lostInfo.lost_at).toLocaleString() : 'Unknown';

        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in">
                <div class="bg-orange-50 border-4 border-orange-500 rounded-2xl p-6 md:p-8">
                    <div class="text-5xl md:text-6xl mb-4">🚫</div>
                    <h2 class="text-2xl md:text-3xl font-bold text-orange-800 mb-3 animate-pulse">RFID CARD DISABLED</h2>
                    
                    ${student.name ? `
                    <div class="bg-white rounded-lg p-4 md:p-5 mb-4 border-2 border-orange-300">
                        <p class="text-xl md:text-2xl font-bold text-slate-800 mb-1">${escHtml(student.name)}</p>
                        <p class="text-slate-600 text-sm">ID: ${escHtml(student.student_id || '')}</p>
                    </div>
                    ` : ''}

                    <div class="bg-orange-600 rounded-lg p-4 mb-4 text-white">
                        <p class="font-bold text-xl md:text-2xl mb-1">⚠️ CARD MARKED AS LOST</p>
                        <p class="text-sm mb-1">This RFID card has been disabled by an administrator.</p>
                        <p class="text-xs opacity-80">Disabled on: ${escHtml(lostDate)}</p>
                    </div>

                    <div class="bg-blue-50 border-2 border-blue-400 rounded-lg p-4 mb-3">
                        <p class="text-blue-900 font-bold text-base mb-2">📧 STUDENT MUST CONTACT:</p>
                        <p class="text-blue-800 text-lg font-semibold">Student Services Office</p>
                        <p class="text-blue-700 text-sm">Email: <strong>studentservices@pcu.edu.ph</strong></p>
                    </div>

                    <p class="text-orange-700 font-bold text-sm mt-3">🔒 Entry via this card is NOT permitted until re-enabled</p>
                </div>
            </div>
        `;

        // Add to scan log with lost indicator
        if (student.name) {
            addToScanLog({
                name: student.name,
                student_id: student.student_id || '',
                email: '',
                violation_count: 0,
                severity: 'lost'
            }, data.timestamp || new Date().toISOString());
        }

        // Keep on screen longer so guard can read the info
        setTimeout(resetScanDisplay, 7000);
    }

    function displayErrorScan(data, uid) {
        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in">
                <div class="bg-red-50 border-4 border-red-500 rounded-2xl p-6 md:p-8">
                    <div class="text-5xl md:text-6xl mb-4">❌</div>
                    <h2 class="text-2xl md:text-3xl font-bold text-red-800 mb-3">UNKNOWN CARD</h2>
                    <p class="text-slate-600 mb-2">This RFID card is not registered</p>
                    <code class="bg-slate-100 px-3 py-2 rounded text-sm md:text-base inline-block mb-3">${escHtml(uid)}</code>
                    <p class="text-red-600 text-sm md:text-base">${escHtml(data.error || 'Access Denied')}</p>
                </div>
            </div>
        `;

        setTimeout(resetScanDisplay, 3000);
    }

    function displayAccessDenied(data) {
        const student = data.student;
        
        document.getElementById('scanStatus').innerHTML = `
            <div class="w-full">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                    <!-- Digital ID Card -->
                    <div id="gateDigitalIdContainer" class="flex justify-center"></div>
                    
                    <!-- Access Denied Info -->
                    <div class="bg-red-50 border-4 border-red-600 rounded-2xl p-6 md:p-8">
                        <div class="text-5xl md:text-6xl mb-4">⛔</div>
                        <h2 class="text-2xl md:text-3xl font-bold text-red-900 mb-3 animate-pulse">ACCESS DENIED</h2>
                        
                        <div class="bg-white rounded-lg p-4 md:p-5 mb-4 border-2 border-red-300">
                            <p class="text-xl md:text-2xl font-bold text-slate-800 mb-2">${escHtml(student.name)}</p>
                            <p class="text-slate-600 mb-1">ID: ${escHtml(student.student_id)}</p>
                            ${student.course ? `<p class="text-slate-500 text-sm mb-1">${escHtml(student.course)}</p>` : ''}
                            <p class="text-slate-500 text-sm">${escHtml(student.email)}</p>
                        </div>
                        <div class="bg-red-600 rounded-lg p-4 mb-4 text-white">
                            <p class="font-bold text-xl md:text-2xl mb-1">🚫 ENTRY NOT ALLOWED</p>
                            <p class="text-lg mb-1">Total Violations: <strong>${escHtml(String(student.violation_count))}</strong></p>
                            <p class="text-sm">Maximum 3-strike limit exceeded</p>
                        </div>
                        <div class="bg-yellow-100 border-2 border-yellow-500 rounded-lg p-4 mb-3">
                            <p class="text-yellow-900 font-bold text-sm mb-1">⚠️ ACTION REQUIRED</p>
                            <p class="text-yellow-800 text-sm">${escHtml(data.message)}</p>
                        </div>
                        <p class="text-red-700 font-bold text-sm mt-3 animate-pulse">🔒 STUDENT MUST CONTACT ADMINISTRATION OFFICE</p>
                    </div>
                </div>
            </div>
        `;

        // Render Digital ID Card for denied student
        const deniedIdCard = new DigitalIdCard('#gateDigitalIdContainer', {
            templateSrc: '../assets/images/id-card-template.png',
            student: {
                name: student.name || '',
                studentId: student.student_id || '',
                course: student.course || '',
                email: student.email || '',
                profilePicture: student.profile_picture
                    ? '../assets/images/profiles/' + student.profile_picture
                    : null
            }
        });
        deniedIdCard.render();

        // Add to log
        addToScanLog(student, data.timestamp || new Date().toISOString());

        // Keep on screen long enough for guard to read both ID and denial info
        setTimeout(resetScanDisplay, 7000);
    }

    function resetScanDisplay() {
        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in">
                <div class="mb-6 relative inline-block">
                    <div class="pulse-ring absolute inset-0 bg-blue-400 rounded-full opacity-20"></div>
                    <svg class="w-24 h-24 md:w-32 md:h-32 text-slate-300 relative z-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        <circle cx="12" cy="12" r="3" stroke-width="1.5"/>
                    </svg>
                </div>
                <h2 class="text-2xl md:text-3xl font-bold text-slate-700 mb-2">Ready to Scan</h2>
                <p class="text-slate-500 text-sm md:text-base">Hold RFID card near scanner</p>
            </div>
        `;
    }

    function addToScanLog(student, timestamp) {
        const time = new Date(timestamp).toLocaleTimeString();
        
        let badgeColor = 'green';
        if (student.violation_count >= 5) badgeColor = 'red';
        else if (student.violation_count >= 3) badgeColor = 'orange';
        else if (student.violation_count >= 2) badgeColor = 'yellow';
        
        scanLog.unshift({student, time});
        
        const logHTML = scanLog.map((log, index) => `
            <div class="bg-white rounded-lg p-4 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 border border-slate-200 shadow-sm">
                <div class="flex-1">
                    <p class="font-semibold text-slate-800 text-sm md:text-base">${escHtml(log.student.name)}</p>
                    <p class="text-xs md:text-sm text-slate-500">${escHtml(log.student.student_id)} • ${escHtml(log.student.email)}</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <p class="text-${badgeColor}-600 font-bold text-sm md:text-base">Strike #${escHtml(String(log.student.violation_count))}</p>
                        <p class="text-xs text-slate-400">${log.time}</p>
                    </div>
                    ${log.student.violation_count >= 3 ? '<span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full animate-pulse font-medium">⚠️ Alert</span>' : ''}
                </div>
            </div>
        `).join('');
        
        document.getElementById('scanLog').innerHTML = logHTML || '<p class="text-center text-slate-400 py-8 text-sm">No scans yet</p>';
    }

    function clearLog() {
        if (confirm('Clear all scan logs from this session?')) {
            scanLog = [];
            document.getElementById('scanLog').innerHTML = '<p class="text-center text-slate-400 py-8 text-sm">Log cleared. Waiting for scans...</p>';
        }
    }

    // Auto-focus on page load to ensure RFID scanner works
    window.addEventListener('load', function() {
        document.body.focus();
        // Pre-warm the ID card template image into browser cache so first scan is instant
        var _warmImg = new Image();
        _warmImg.src = '../assets/images/id-card-template.png';
    });

    <?php if ($faceRecEnabled): ?>
    // ================================================================
    // FACE RECOGNITION MODE
    // ================================================================
    const FACE_THRESHOLD = <?php echo json_encode($faceMatchThreshold); ?>;
    let currentMode = 'rfid';
    let faceSystem = null;
    let faceInitialized = false;
    let faceReady = false;         // models + descriptors loaded and ready to detect
    let cameraRunning = false;      // true while a camera stream is active
    let selectedCameraId = null;    // deviceId of the chosen camera (null = browser default)
    let lastFaceMatchUserId = null;
    let lastFaceMatchTime = 0;
    const FACE_COOLDOWN_MS = 5000; // 5 second cooldown between same-person matches

    function switchMode(mode) {
        currentMode = mode;
        const rfidPanel = document.getElementById('rfidPanel');
        const facePanel = document.getElementById('facePanel');
        const btnRfid = document.getElementById('btnRfidMode');
        const btnFace = document.getElementById('btnFaceMode');

        if (mode === 'rfid') {
            rfidPanel.classList.remove('hidden');
            facePanel.classList.add('hidden');
            btnRfid.className = 'flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-sky-600 text-white shadow-md';
            btnFace.className = 'flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-white/80 text-slate-600 border border-white/60 hover:bg-white';
            
            // SECURITY: Fully stop and release camera hardware so the camera LED turns off
            // stopContinuousDetection() only pauses the detection loop — stopCamera() actually
            // releases the MediaStream tracks so no unknown process can access the camera.
            if (faceSystem) {
                faceSystem.stopContinuousDetection();
                faceSystem.stopCamera();
            }
            stopFaceAutoRefresh();
            cameraRunning = false;
            // Hide Start/Stop buttons while in RFID mode
            document.getElementById('btnStartFace').classList.add('hidden');
            document.getElementById('btnStopFace').classList.add('hidden');
            document.body.focus();
        } else {
            rfidPanel.classList.add('hidden');
            facePanel.classList.remove('hidden');
            btnFace.className = 'flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-sky-600 text-white shadow-md';
            btnRfid.className = 'flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-white/80 text-slate-600 border border-white/60 hover:bg-white';
            
            if (!faceInitialized) {
                // First time — enumerate cameras immediately (fire-and-forget, no await needed)
                // then load models + faces, then auto-start camera preview
                populateCameraSelector();
                initFaceRecognition();
            } else {
                const faceCount = parseInt(document.getElementById('faceLoadedCount').textContent) || 0;
                document.getElementById('faceStatus').textContent = faceReady
                    ? `Ready - ${faceCount} faces loaded. Click Start to begin detection.`
                    : `Initializing face engine... ${faceCount} faces loaded so far.`;
                if (faceReady) {
                    document.getElementById('btnStartFace').classList.remove('hidden');
                } else {
                    document.getElementById('btnStartFace').classList.add('hidden');
                }
                document.getElementById('btnStopFace').classList.add('hidden');
                if (!cameraRunning) {
                    restartCamera();
                }
            }
        }
    }

    // Re-opens the camera stream after it was released (e.g. after switching to RFID mode)
    async function restartCamera() {
        const statusEl = document.getElementById('faceStatus');
        document.getElementById('faceVideoContainer').classList.remove('hidden');
        statusEl.textContent = 'Starting camera...';
        const cameraOk = await faceSystem.startCamera(
            document.getElementById('faceVideo'),
            document.getElementById('faceCanvas'),
            selectedCameraId
        );
        if (cameraOk) {
            cameraRunning = true;
            const faceCount = parseInt(document.getElementById('faceLoadedCount').textContent) || 0;
            statusEl.textContent = `Ready - ${faceCount} faces loaded. Click Start to begin detection.`;
            document.getElementById('btnStartFace').classList.remove('hidden');
            document.getElementById('faceVideoContainer').classList.remove('hidden');
        } else {
            statusEl.textContent = '❌ Camera failed. Check permissions.';
            cameraRunning = false;
        }
    }

    // Populates the camera selector dropdown
    // Safe to call at any time — early calls get generic labels, post-permission calls get real device names
    async function populateCameraSelector() {
        if (!faceSystem && !navigator.mediaDevices) return;
        let cameras = [];
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            cameras = devices
                .filter(d => d.kind === 'videoinput')
                .map((d, i) => ({ deviceId: d.deviceId, label: d.label || `Camera ${i + 1}` }));
        } catch (e) { return; }

        const select = document.getElementById('cameraSelect');
        const hint  = document.getElementById('cameraSelectHint');
        if (!select) return;

        // Preserve current value if possible
        const current = selectedCameraId || select.value;
        select.innerHTML = '';

        if (cameras.length === 0) {
            select.innerHTML = '<option value="">No cameras detected</option>';
            if (hint) hint.textContent = 'No cameras found. Connect a webcam and click Refresh.';
            return;
        }

        cameras.forEach((cam, i) => {
            const opt = document.createElement('option');
            opt.value = cam.deviceId;
            opt.textContent = cam.label || `Camera ${i + 1}`;
            if (cam.deviceId && cam.deviceId === current) opt.selected = true;
            select.appendChild(opt);
        });

        // Sync selectedCameraId
        if (!selectedCameraId || !cameras.find(c => c.deviceId === selectedCameraId)) {
            selectedCameraId = select.value;
        } else {
            select.value = selectedCameraId;
        }

        if (hint) {
            hint.textContent = cameras.length === 1
                ? '1 camera detected. Connect more cameras and click Refresh to see them.'
                : `${cameras.length} cameras detected. Select the one you want to use.`;
        }
    }

    // Called when admin picks a different camera from the dropdown
    async function changeCameraDevice(deviceId) {
        if (!deviceId) return;
        // Allow re-selecting even the same device (force refresh)
        if (!faceSystem) return;

        // Stop detection loop if running
        if (faceSystem._continuousRunning) stopFaceDetection();

        document.getElementById('faceStatus').textContent = 'Switching camera...';

        // Use switchCamera which gets the NEW stream BEFORE killing the old one.
        // This is the only reliable way on Chrome/Windows — if you stop first,
        // the browser recycles the old device handle to the new getUserMedia call.
        const cameraOk = await faceSystem.switchCamera(deviceId);

        if (cameraOk) {
            selectedCameraId = deviceId;
            cameraRunning = true;
            const faceCount = parseInt(document.getElementById('faceLoadedCount').textContent) || 0;
            document.getElementById('faceStatus').textContent =
                `Camera switched — ${faceCount} faces loaded. Click Start to begin detection.`;
            document.getElementById('btnStartFace').classList.remove('hidden');
            document.getElementById('btnStopFace').classList.add('hidden');
        } else {
            document.getElementById('faceStatus').textContent = '❌ Camera switch failed. Try another camera.';
        }
    }

    async function initFaceRecognition() {
        const statusEl = document.getElementById('faceStatus');
        const initEl = document.getElementById('faceInitStatus');

        faceSystem = new FaceRecognitionSystem({
            modelPath: '../assets/models',
            matchThreshold: FACE_THRESHOLD,
            minConfidence: 0.5,
            csrfToken: CSRF_TOKEN,
            detectionIntervalMs: 120,  // Faster detection loop for near-instant start
            ssdInputSize: 160,         // Much faster inference on CPU while keeping usable accuracy
            onStatusChange: (status, msg) => {
                statusEl.textContent = msg;
            },
            onError: (msg, err) => {
                statusEl.textContent = '❌ ' + msg;
                console.error('[FaceRec]', msg, err);
            },
            onDetection: handleFaceDetection
        });

        // Step 1: Immediately show panel and start camera preview (non-blocking UX)
        faceInitialized = true;
        faceReady = false;
        cameraRunning = false;
        initEl.classList.add('hidden');
        document.getElementById('btnStartFace').classList.add('hidden');
        document.getElementById('btnStopFace').classList.add('hidden');
        statusEl.textContent = 'Starting camera preview...';
        restartCamera();

        // Step 2: Load models + face descriptors in parallel while preview is already visible
        statusEl.textContent = 'Initializing face engine...';
        const [modelsOk, faceCount] = await Promise.all([
            faceSystem.loadModels(),
            faceSystem.loadKnownFaces('../api/get_face_descriptors.php')
        ]);

        document.getElementById('faceLoadedCount').textContent = faceCount;

        if (!modelsOk) {
            statusEl.textContent = '❌ Failed to load face models. Check model files in assets/models.';
            faceReady = false;
            return;
        }

        // Ready for instant Start
        faceReady = true;
        statusEl.textContent = `Ready - ${faceCount} faces loaded. Click Start to begin detection.`;
        document.getElementById('btnStartFace').classList.remove('hidden');
        await populateCameraSelector();
    }

    async function startFaceDetection() {
        if (!faceSystem) return;
        if (!faceReady) {
            document.getElementById('faceStatus').textContent = 'Initializing face engine... please wait a moment.';
            return;
        }

        // Start camera only when operator clicks Start (keeps Face tab switch instant)
        if (!cameraRunning) {
            document.getElementById('faceStatus').textContent = 'Starting camera...';
            let cameraOk = await faceSystem.startCamera(
                document.getElementById('faceVideo'),
                document.getElementById('faceCanvas'),
                selectedCameraId
            );

            // Retry path: clear selected device and force default camera
            if (!cameraOk) {
                document.getElementById('faceStatus').textContent = 'Retrying with default camera...';
                selectedCameraId = null;
                cameraOk = await faceSystem.startCamera(
                    document.getElementById('faceVideo'),
                    document.getElementById('faceCanvas'),
                    null
                );
            }

            if (!cameraOk || !faceSystem.stream || faceSystem.stream.getVideoTracks().length === 0) {
                document.getElementById('faceStatus').textContent =
                    '❌ Camera failed to start. Close other camera apps and allow browser permission, then click Start again.';
                cameraRunning = false;
                return;
            }

            cameraRunning = true;
            document.getElementById('faceVideoContainer').classList.remove('hidden');
            // Permission is now granted, refresh labels/device IDs
            await populateCameraSelector();
        }

        faceSystem.startContinuousDetection();
        document.getElementById('btnStartFace').classList.add('hidden');
        document.getElementById('btnStopFace').classList.remove('hidden');
        document.getElementById('faceStatus').textContent = '🟢 Detection active - scanning faces...';
        
        // Start periodic face database refresh (every 30 seconds)
        startFaceAutoRefresh();
    }

    function stopFaceDetection() {
        if (!faceSystem) return;
        faceSystem.stopContinuousDetection();
        document.getElementById('btnStopFace').classList.add('hidden');
        document.getElementById('btnStartFace').classList.remove('hidden');
        document.getElementById('faceStatus').textContent = '⏸ Detection paused';
        
        // Stop auto-refresh when detection is paused
        stopFaceAutoRefresh();
    }

    // Auto-refresh face database to pick up new enrollments
    let faceRefreshTimer = null;
    const FACE_REFRESH_INTERVAL = 30000; // 30 seconds

    function startFaceAutoRefresh() {
        stopFaceAutoRefresh();
        faceRefreshTimer = setInterval(async () => {
            if (!faceSystem) return;
            try {
                const newCount = await faceSystem.loadKnownFaces('../api/get_face_descriptors.php');
                const currentCount = parseInt(document.getElementById('faceLoadedCount').textContent) || 0;
                document.getElementById('faceLoadedCount').textContent = newCount;
                
                if (newCount > currentCount) {
                    document.getElementById('faceStatus').textContent = 
                        `🟢 Detection active - ${newCount} faces loaded (${newCount - currentCount} new)`;
                    console.log(`[FaceRec] Auto-refresh: ${newCount - currentCount} new face(s) loaded`);
                }
            } catch (err) {
                console.warn('[FaceRec] Auto-refresh failed:', err);
            }
        }, FACE_REFRESH_INTERVAL);
    }

    function stopFaceAutoRefresh() {
        if (faceRefreshTimer) {
            clearInterval(faceRefreshTimer);
            faceRefreshTimer = null;
        }
    }

    async function handleFaceDetection(result) {
        if (!result.matched || !result.match) {
            // No match - hide overlay
            document.getElementById('faceMatchOverlay').classList.add('hidden');
            return;
        }

        const match = result.match;
        const now = Date.now();

        // Show overlay with match info
        const overlay = document.getElementById('faceMatchOverlay');
        const infoEl = document.getElementById('faceMatchInfo');
        overlay.classList.remove('hidden');
        infoEl.innerHTML = `
            <p class="font-bold text-lg">${escHtml(match.name)}</p>
            <p class="text-sm opacity-80">${escHtml(match.studentId)} • Confidence: ${(match.confidence * 100).toFixed(1)}%</p>
        `;

        // Cooldown check - prevent duplicate entries for same person
        if (match.userId === lastFaceMatchUserId && (now - lastFaceMatchTime) < FACE_COOLDOWN_MS) {
            return;
        }

        lastFaceMatchUserId = match.userId;
        lastFaceMatchTime = now;

        // Pause detection while processing
        faceSystem.stopContinuousDetection();
        document.getElementById('faceStatus').textContent = '⏳ Processing match...';

        // Log the face entry to backend
        const response = await faceSystem.logFaceEntry(
            match.userId,
            parseFloat(match.confidence),
            '../api/log_face_entry.php'
        );

        // Display result (same style as RFID)
        displayFaceScanResult(response, match);

        // Stop detection completely — guard must press Start for next student
        stopFaceAutoRefresh();
        document.getElementById('btnStopFace').classList.add('hidden');
        document.getElementById('btnStartFace').classList.remove('hidden');
        document.getElementById('faceStatus').textContent = '✅ Scan complete — press Start to scan next student';

        // Clear the result display after delay but keep detection stopped
        setTimeout(() => {
            const resultEl = document.getElementById('faceScanResult');
            resultEl.classList.add('hidden');
            overlay.classList.add('hidden');
        }, 4000);
    }

    function renderFaceDigitalId(containerSelector, student, match) {
        const profilePictureFile = student.profile_picture || match.profilePicture || null;
        const idCard = new DigitalIdCard(containerSelector, {
            templateSrc: '../assets/images/id-card-template.png',
            student: {
                name: student.name || match.name || '',
                studentId: student.student_id || match.studentId || '',
                course: student.course || match.course || '',
                email: student.email || match.email || '',
                profilePicture: profilePictureFile
                    ? '../assets/images/profiles/' + profilePictureFile
                    : null
            }
        });
        idCard.render();
    }

    function displayFaceScanResult(response, match) {
        const resultEl = document.getElementById('faceScanResult');
        resultEl.classList.remove('hidden');

        if (response.access_denied) {
            const student = response.student || {};
            resultEl.innerHTML = `
                <div class="w-full fade-in">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                        <div id="faceDigitalIdContainer" class="flex justify-center"></div>
                        <div class="bg-red-50 border-4 border-red-600 rounded-2xl p-6 md:p-8">
                            <div class="text-5xl md:text-6xl mb-4">⛔</div>
                            <h3 class="text-2xl md:text-3xl font-bold text-red-900 mb-3">ACCESS DENIED</h3>
                            <div class="bg-white rounded-lg p-4 md:p-5 mb-4 border-2 border-red-300">
                                <p class="text-xl md:text-2xl font-bold text-slate-800 mb-2">${escHtml(student.name || match.name || '')}</p>
                                <p class="text-slate-600 mb-1">ID: ${escHtml(student.student_id || match.studentId || '')}</p>
                                ${(student.course || match.course) ? `<p class="text-slate-500 text-sm mb-1">${escHtml(student.course || match.course)}</p>` : ''}
                                <p class="text-slate-500 text-sm">${escHtml(student.email || match.email || '')}</p>
                            </div>
                            <div class="bg-red-600 rounded-lg p-4 mb-4 text-white">
                                <p class="font-bold text-xl md:text-2xl mb-1">🚫 ENTRY NOT ALLOWED</p>
                                <p class="text-lg mb-1">Total Violations: <strong>${escHtml(String(student.violation_count || '3+'))}</strong></p>
                                <p class="text-sm">Maximum 3-strike limit exceeded</p>
                            </div>
                            <div class="bg-yellow-100 border-2 border-yellow-500 rounded-lg p-4 mb-3">
                                <p class="text-yellow-900 font-bold text-sm mb-1">⚠️ ACTION REQUIRED</p>
                                <p class="text-yellow-800 text-sm">${escHtml(response.message || 'Entry denied')}</p>
                            </div>
                            <p class="text-red-700 font-bold text-sm mt-3">🔒 STUDENT MUST CONTACT ADMINISTRATION OFFICE</p>
                        </div>
                    </div>
                </div>`;

            renderFaceDigitalId('#faceDigitalIdContainer', student, match);
            addToScanLog({
                name: student.name || match.name,
                student_id: student.student_id || match.studentId,
                email: student.email || match.email,
                violation_count: student.violation_count || 0,
                course: student.course || match.course || ''
            }, new Date().toISOString());
        } else if (response.success) {
            const student = response.student;
            let severityColor = student.severity === 'critical' ? 'red' : student.severity === 'medium' ? 'yellow' : 'green';
            let severityBg = student.severity === 'critical' ? 'bg-red-50' : student.severity === 'medium' ? 'bg-yellow-50' : 'bg-green-50';
            let severityBorder = student.severity === 'critical' ? 'border-red-500' : student.severity === 'medium' ? 'border-yellow-400' : 'border-green-400';
            let severityIcon = student.severity === 'critical' ? '⚠️' : student.severity === 'medium' ? '⚡' : '✓';
            
            resultEl.innerHTML = `
                <div class="w-full fade-in">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                        <div id="faceDigitalIdContainer" class="flex justify-center"></div>
                        <div class="${severityBg} border-4 ${severityBorder} rounded-2xl p-6 md:p-8">
                            <div class="text-5xl md:text-6xl mb-4">${severityIcon}</div>
                            <h3 class="text-2xl md:text-3xl font-bold text-${severityColor}-800 mb-3">NO PHYSICAL ID</h3>
                            <div class="bg-white rounded-lg p-4 md:p-6 mb-4">
                                <p class="text-xl md:text-2xl font-bold text-slate-800 mb-2">${escHtml(student.name)}</p>
                                <p class="text-slate-600 mb-1">ID: ${escHtml(student.student_id)}</p>
                                ${(student.course || match.course) ? `<p class="text-slate-500 text-sm mb-1">${escHtml(student.course || match.course)}</p>` : ''}
                                <p class="text-slate-500 text-sm">${escHtml(student.email)}</p>
                            </div>
                            <div class="bg-${severityColor}-100 rounded-lg p-4 mb-3">
                                <p class="text-${severityColor}-900 font-bold text-2xl md:text-3xl mb-1">Violation #${escHtml(String(student.violation_count))}</p>
                                <p class="text-${severityColor}-700 text-sm md:text-base">${escHtml(student.severity_message)}</p>
                            </div>
                            <p class="text-slate-600 text-sm md:text-base">${escHtml(response.message || 'Face recognized - Student forgot physical ID')}</p>
                            ${student.violation_count === 3 ? '<p class="text-red-600 font-bold mt-3 text-sm md:text-base">🚨 FINAL WARNING - Next time entry will be DENIED!</p>' : ''}
                            <p class="text-xs text-slate-400 mt-2">Match confidence: ${(match.confidence * 100).toFixed(1)}%</p>
                        </div>
                    </div>
                </div>`;

            renderFaceDigitalId('#faceDigitalIdContainer', student, match);
            addToScanLog(student, response.timestamp);
        } else {
            resultEl.innerHTML = `
                <div class="bg-yellow-50 border-2 border-yellow-400 rounded-xl p-4 text-center fade-in">
                    <p class="text-yellow-800 font-medium">${escHtml(response.error || 'Unknown error')}</p>
                </div>`;
        }
    }
    <?php endif; ?>
    </script>
</body>
</html>
