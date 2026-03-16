<?php

require_once 'db.php';

// Enhanced session security check
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id']) || empty($_SESSION['user']['email'])) {
    // Clear any existing session data
    session_unset();
    session_destroy();
    
    // Start new session for toast message
    session_start();
    $_SESSION['toast'] = 'Please log in to access the system';
    
    // Redirect to login
    header('Location: login.php');
    exit;
}

// Prevent session fixation
if (!isset($_SESSION['last_activity'])) {
    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
}

// Session timeout after 30 minutes of inactivity
if (time() - $_SESSION['last_activity'] > 1800) {
    session_unset();
    session_destroy();
    
    // Start new session for toast message
    session_start();
    $_SESSION['toast'] = 'Session expired. Please log in again';
    
    header('Location: login.php');
    exit;
}

// Update last activity time
$_SESSION['last_activity'] = time();

$user = $_SESSION['user'];

// Get complete user information from database
try {
    $pdo = pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND email = ? LIMIT 1');
    $stmt->execute([$user['id'], $user['email']]);
    $userData = $stmt->fetch();
    
    if (!$userData || $userData['status'] !== 'Active') {
        session_unset();
        session_destroy();
        
        // Start new session for toast message
        session_start();
        $_SESSION['toast'] = 'Your account is no longer active. Please contact support.';
        
        header('Location: login.php');
        exit;
    }
    
    // Update user data with complete information from database
    $user = $userData;
    
} catch (\Exception $e) {
    error_log('Database verification failed in homepage: ' . $e->getMessage());
    
    session_unset();
    session_destroy();
    
    // Start new session for toast message
    session_start();
    $_SESSION['toast'] = 'System error. Please try again later.';
    
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GateWatch | Home</title>
    <link rel="icon" type="image/png" href="assets/images/gatewatch-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>
    <script src="https://unpkg.com/@heroicons/v2/24/outline/esm/index.js"></script>
    <style>
        :root {
            --sky-50: #f0f9ff;
            --sky-100: #e0f2fe;
            --sky-600: #0284c7;
            --slate-900: #0f172a;
            --glass: rgba(255, 255, 255, 0.65);
        }

        html {
            margin: 0;
            padding: 0;
            height: 100%;
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            background: #e0f2ff;
            background: radial-gradient(circle at 20% 20%, rgba(2, 132, 199, 0.07), transparent 30%),
                        radial-gradient(circle at 80% 10%, rgba(59, 130, 246, 0.08), transparent 25%),
                        linear-gradient(135deg, #e0f2ff 0%, #f8fbff 100%);
            background-attachment: fixed;
            overflow-x: hidden;
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
            background-image: url('assets/images/pcu-building.jpg');
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
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.14), rgba(255, 255, 255, 0.7));
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

        .frosted {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .pill {
            border-radius: 9999px;
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
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 35px rgba(15, 23, 42, 0.08);
        }

        .stat-card:hover::after {
            opacity: 1;
        }

        .avatar-ring {
            position: relative;
        }

        .avatar-ring::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 9999px;
            background: conic-gradient(from 120deg, rgba(2, 132, 199, 0.25), rgba(14, 165, 233, 0.4), rgba(2, 132, 199, 0.25));
            animation: rotate 6s linear infinite;
            z-index: 0;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .hero-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 1.5rem;
        }

        @media (max-width: 1024px) {
            .hero-grid {
                grid-template-columns: repeat(1, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .floating-blob {
                display: none;
            }

            .avatar-ring::before {
                content: none;
                animation: none;
            }
        }

        .section-title {
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -8px;
            width: 64px;
            height: 4px;
            background: linear-gradient(90deg, #0284c7, #38bdf8);
            border-radius: 999px;
        }

        .accordion {
            transition: max-height 0.35s ease, opacity 0.35s ease;
        }

        .chip {
            border: 1px solid rgba(2, 132, 199, 0.15);
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.1), rgba(255, 255, 255, 0.6));
        }

        .cta-shadow {
            box-shadow: 0 20px 60px rgba(14, 165, 233, 0.2);
        }

        .nav-blur {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        @font-face {
            font-family: 'old-english-canterbury';
            src: url('assets/fonts/canterbury-webfont.woff2') format('woff2'),
                 url('assets/fonts/canterbury-webfont.woff') format('woff');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }

        .brand-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
        }

        .brand-logo {
            width: 2.5rem;
            height: 2.5rem;
            flex-shrink: 0;
        }

        .brand-wordmark {
            font-family: 'old-english-canterbury', serif;
            font-weight: 400;
            font-size: clamp(1.05rem, 2.2vw, 1.875rem);
            line-height: 1;
            letter-spacing: 0;
            color: #000000;
            text-rendering: optimizeLegibility;
            white-space: nowrap;
        }

        .sidebar-button {
            transition: all 0.2s ease;
        }

        .sidebar-button:hover,
        .sidebar-button:focus,
        .sidebar-button[aria-expanded="true"] {
            background-color: #0284c7;
            color: white;
        }

        .profile-menu {
            transform-origin: top right;
            transition: transform 0.18s ease-out, opacity 0.18s ease-out;
        }

        .profile-menu[hidden] {
            display: none;
            transform: scale(0.95);
            opacity: 0;
        }

        .profile-menu:not([hidden]) {
            display: block;
            transform: scale(1);
            opacity: 1;
        }

        .hover-button {
            transition: all 0.25s ease;
        }

        .hover-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }

        @media (max-width: 768px) {
            .brand-link {
                gap: 0.4rem;
            }

            .brand-logo {
                width: 2rem;
                height: 2rem;
            }

            .brand-wordmark {
                font-size: clamp(0.88rem, 3.5vw, 1.05rem);
                white-space: normal;
                line-height: 1.15;
                letter-spacing: 0;
                max-width: calc(100vw - 10rem);
            }
        }
    </style>
</head>
<body class="text-slate-900">
    <div class="page-shell">
        <div class="hero-photo"></div>
        <div class="hero-gradient"></div>
        <span class="floating-blob blob-1"></span>
        <span class="floating-blob blob-2"></span>

        <nav class="nav-blur border-b border-slate-200 fixed w-full top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between min-h-[4rem] py-2 items-center">
                    <div class="flex items-center gap-3">
                        <a href="homepage.php" class="brand-link hover:opacity-90 transition-opacity">
                            <img src="assets/images/pcu-logo.png" alt="PCU Logo" class="brand-logo">
                            <span class="brand-wordmark">Philippine Christian University</span>
                        </a>

                    </div>

                    <div class="flex items-center">
                        <form action="auth.php" method="POST" class="ml-3">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="sidebar-button px-4 py-2 rounded-full text-sm font-semibold text-slate-700 border border-slate-300 hover:text-white focus:outline-none focus:ring-2 focus:ring-sky-500">
                                Sign Out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </nav>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-12 relative z-10 flex-1">
            <?php
                $firstLetter = strtoupper(substr($user['name'], 0, 1));
                $colors = ['bg-blue-500', 'bg-green-500', 'bg-yellow-500', 'bg-red-500', 'bg-purple-500', 'bg-pink-500'];
                $colorIndex = ord($firstLetter) % count($colors);
                $bgColor = $colors[$colorIndex];
                $hasProfilePicture = !empty($user['profile_picture']);
                $profilePictureUrl = $hasProfilePicture ? 'assets/images/profiles/' . htmlspecialchars($user['profile_picture']) : '';
            ?>

            <section class="hero-grid items-start mt-6">
                <div class="lg:col-span-5 md:col-span-12">
                    <div class="glass-card rounded-3xl p-6 sm:p-7 card-animate">
                        <div class="flex items-center gap-4">
                            <div class="avatar-ring">
                                <?php if ($hasProfilePicture): ?>
                                    <div class="relative z-10 w-20 h-20 sm:w-24 sm:h-24 rounded-full overflow-hidden border-4 border-white shadow-lg">
                                        <img src="<?= $profilePictureUrl ?>" alt="Profile Picture" class="w-full h-full object-cover">
                                    </div>
                                <?php else: ?>
                                    <div class="relative z-10 w-20 h-20 sm:w-24 sm:h-24 rounded-full <?= $bgColor ?> border-4 border-white shadow-lg flex items-center justify-center text-white text-4xl sm:text-5xl font-bold">
                                        <?= htmlspecialchars($firstLetter) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm uppercase tracking-[0.18em] text-sky-600 font-semibold mb-1">Student Access</p>
                                <h1 class="text-3xl sm:text-4xl font-bold text-slate-900 leading-tight">Welcome back, <?= htmlspecialchars($user['name']) ?></h1>
                                <div class="flex flex-wrap items-center gap-2 mt-3">
                                    <span class="badge bg-sky-50 text-sky-700 border border-sky-100">Role: <?= htmlspecialchars($user['role']) ?></span>
                                    <span class="badge bg-sky-50 text-sky-700 border border-sky-100">Student ID: <?= htmlspecialchars($user['student_id'] ?? 'N/A') ?></span>
                                </div>
                            </div>
                        </div>


                        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <button onclick="window.location.href='digital_id.php'" class="w-full h-12 pill bg-sky-600 text-white font-semibold shadow-lg cta-shadow hover:bg-sky-700 transition-colors flex items-center justify-center relative">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute left-4 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" />
                                </svg>
                                <span class="w-full text-center">Open Digital ID</span>
                            </button>
                            <button onclick="toggleContactSupport()" class="w-full h-12 pill bg-white text-slate-800 font-semibold border border-slate-200 hover:border-sky-200 hover:shadow-md transition-colors flex items-center justify-center relative">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-sky-600 absolute left-4 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                </svg>
                                <span class="w-full text-center">Contact Support</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-7 md:col-span-12">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="glass-card rounded-3xl p-5 card-animate stat-card">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Account</p>
                                    <h3 class="text-xl font-semibold text-slate-900">Status</h3>
                                </div>
                                <span class="badge bg-green-50 text-green-700 border border-green-100">Active</span>
                            </div>
                            <p class="text-slate-600 leading-relaxed">Keep your account active to avoid access delays at the gate.</p>
                        </div>

                        <?php
                        $rfidIsLost = false;
                        $rfidLostReason = '';
                        if (!empty($user['rfid_uid'])) {
                            try {
                                $lostStmt = $pdo->prepare("SELECT is_lost, lost_reason FROM rfid_cards WHERE user_id = ? AND rfid_uid = ? ORDER BY id DESC LIMIT 1");
                                $lostStmt->execute([$user['id'], $user['rfid_uid']]);
                                $rfidStatus = $lostStmt->fetch();
                                $rfidIsLost = $rfidStatus && $rfidStatus['is_lost'] == 1;
                                $rfidLostReason = $rfidStatus['lost_reason'] ?? '';
                            } catch (\PDOException $e) {
                                $rfidIsLost = false;
                            }
                        }
                        $cardBgColor = !empty($user['rfid_uid']) ? ($rfidIsLost ? 'red' : 'green') : 'amber';
                        $cardStatus = !empty($user['rfid_uid']) ? ($rfidIsLost ? 'LOST - Use Digital ID' : 'Active') : 'Not Active';

                        // Check face recognition enrollment
                        $faceEnrolled = false;
                        try {
                            $faceStmt = $pdo->prepare('SELECT COUNT(*) FROM face_descriptors WHERE user_id = ? AND is_active = 1');
                            $faceStmt->execute([$user['id']]);
                            $faceEnrolled = (int)$faceStmt->fetchColumn() > 0;
                        } catch (\PDOException $e) {
                            $faceEnrolled = false;
                        }
                        ?>
                        <div class="glass-card rounded-3xl p-5 card-animate stat-card">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.12em] text-slate-500">RFID</p>
                                    <h3 class="text-xl font-semibold text-slate-900">Card Status</h3>
                                </div>
                                <span class="badge bg-<?= $cardBgColor ?>-50 text-<?= $cardBgColor ?>-700 border border-<?= $cardBgColor ?>-100">
                                    <?= htmlspecialchars($cardStatus) ?>
                                </span>
                            </div>
                            <p class="text-slate-600 leading-relaxed">
                                <?= $rfidIsLost ? 'Marked lost. Please use Digital ID until a replacement is issued.' : 'Present your RFID or Digital ID at the gate for smooth entry.' ?>
                            </p>
                        </div>

                        <div class="glass-card rounded-3xl p-5 card-animate stat-card">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Violations</p>
                                    <h3 class="text-xl font-semibold text-slate-900">Record</h3>
                                </div>
                                <span class="badge bg-rose-50 text-rose-700 border border-rose-100">
                                    <?= $user['violation_count'] ?? 0 ?> total
                                </span>
                            </div>
                            <p class="text-slate-600 leading-relaxed">Stay compliant to keep this at zero. Need help? Contact support anytime.</p>
                        </div>

                        <div class="glass-card rounded-3xl p-5 card-animate stat-card">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Face</p>
                                    <h3 class="text-xl font-semibold text-slate-900">Recognition</h3>
                                </div>
                                <?php if ($faceEnrolled): ?>
                                <span class="badge bg-green-50 text-green-700 border border-green-100">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Verified
                                </span>
                                <?php else: ?>
                                <span class="badge bg-amber-50 text-amber-700 border border-amber-100">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                    </svg>
                                    Unverified
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-slate-600 leading-relaxed">
                                <?= $faceEnrolled
                                    ? 'Your face is enrolled and ready for Face Recognition entry at supported gates.'
                                    : 'No face enrolled yet. Contact an admin to register your face for gate entry.' ?>
                            </p>
                        </div>

                    </div>
                </div>
            </section>

            <section class="mt-10">
                <div class="mb-6">
                    <h2 class="section-title text-2xl sm:text-3xl font-bold text-slate-900">Gate Entry Guide</h2>
                    <p class="text-slate-600 mt-3">Follow these steps every time you enter campus to avoid violations.</p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 card-animate">
                    <!-- Step 1 -->
                    <div class="glass-card rounded-3xl p-6 stat-card flex flex-col gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-sky-600 grid place-items-center text-white text-xl font-bold leading-none shrink-0 shadow-lg">1</div>
                            <div>
                                <p class="text-xs uppercase tracking-widest text-sky-600 font-semibold">Before You Enter</p>
                                <h3 class="text-lg font-bold text-slate-900">Prepare Your ID</h3>
                            </div>
                        </div>
                        <p class="text-slate-600 leading-relaxed">Have your <strong class="text-slate-800">RFID card</strong> ready in hand, or open your <strong class="text-slate-800">Digital ID</strong> on your phone before reaching the gate. This keeps the line moving for everyone.</p>
                    </div>

                    <!-- Step 2 -->
                    <div class="glass-card rounded-3xl p-6 stat-card flex flex-col gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-sky-600 grid place-items-center text-white text-xl font-bold leading-none shrink-0 shadow-lg">2</div>
                            <div>
                                <p class="text-xs uppercase tracking-widest text-sky-600 font-semibold">At The Gate</p>
                                <h3 class="text-lg font-bold text-slate-900">Scan, Show, or Look</h3>
                            </div>
                        </div>
                        <p class="text-slate-600 leading-relaxed"><strong class="text-slate-800">Tap your RFID card</strong>, let the guard scan your <strong class="text-slate-800">Digital ID QR code</strong>, or use <strong class="text-slate-800">Face Recognition</strong> at supported gates. All three methods are accepted at campus entry points.</p>
                        <div class="mt-auto flex flex-col gap-2">
                            <div class="frosted rounded-xl px-4 py-2.5 border border-sky-100 flex items-center gap-3">
                                <span class="h-2.5 w-2.5 rounded-full bg-green-500 shrink-0"></span>
                                <p class="text-sm text-slate-700">RFID card tap</p>
                            </div>
                            <div class="frosted rounded-xl px-4 py-2.5 border border-sky-100 flex items-center gap-3">
                                <span class="h-2.5 w-2.5 rounded-full bg-green-500 shrink-0"></span>
                                <p class="text-sm text-slate-700">Digital ID QR scan</p>
                            </div>
                            <div class="frosted rounded-xl px-4 py-2.5 border border-sky-100 flex items-center gap-3">
                                <span class="h-2.5 w-2.5 rounded-full bg-sky-500 shrink-0"></span>
                                <p class="text-sm text-slate-700">Face Recognition </span></p>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="glass-card rounded-3xl p-6 stat-card flex flex-col gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-sky-600 grid place-items-center text-white text-xl font-bold leading-none shrink-0 shadow-lg">3</div>
                            <div>
                                <p class="text-xs uppercase tracking-widest text-sky-600 font-semibold">After Entry</p>
                                <h3 class="text-lg font-bold text-slate-900">Entry Logged</h3>
                            </div>
                        </div>
                        <p class="text-slate-600 leading-relaxed">Your entry is <strong class="text-slate-800">recorded automatically</strong> in real time. Entering without a valid ID will result in a <strong class="text-rose-600">violation record</strong> on your account.</p>
                        <div class="mt-auto frosted rounded-2xl px-4 py-3 border border-rose-100 flex items-center gap-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                            <p class="text-sm text-slate-700">No ID = violation on record</p>
                        </div>
                    </div>
                </div>

                <!-- Lost RFID Banner (shown only if RFID is lost) -->
                <?php if ($rfidIsLost): ?>
                <div class="mt-4 glass-card rounded-3xl p-5 card-animate flex flex-col sm:flex-row items-start sm:items-center gap-4 border border-rose-200">
                    <div class="w-12 h-12 rounded-2xl bg-rose-100 flex items-center justify-center shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div class="flex-1">
                        <p class="font-bold text-rose-700">Your RFID card is marked as lost</p>
                        <p class="text-sm text-slate-600 mt-0.5">Use your <strong>Digital ID QR</strong> for gate entry until a replacement card is issued by the admin.<?= $rfidLostReason ? ' Reason: ' . htmlspecialchars($rfidLostReason) : '' ?></p>
                    </div>
                    <button onclick="window.location.href='digital_id.php'" class="hover-button pill px-5 py-2.5 bg-rose-600 text-white font-semibold text-sm whitespace-nowrap shadow-md">
                        Open Digital ID
                    </button>
                </div>
                <?php endif; ?>
            </section>
        </main>

        <footer class="relative z-10 mt-auto">
            <!-- Main Footer Body -->
            <div style="background: rgba(8, 13, 28, 0.93); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border-top: 1px solid rgba(255,255,255,0.07);">
                <div class="w-full px-5 sm:px-10 lg:px-20 py-8 md:py-10">

                    <!-- Mobile Layout -->
                    <div class="flex lg:hidden" style="flex-direction: column; align-items: center; text-align: center; gap: 1.5rem; max-width: 340px; margin: 0 auto;">

                        <!-- Logo -->
                        <a href="homepage.php" style="width: 80px; height: 80px; border-radius: 50%; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; box-shadow: 0 0 30px rgba(2,132,199,0.12); text-decoration: none;">
                            <img src="assets/images/pcu-logo.png" alt="Philippine Christian University Logo" style="width: 56px; height: 56px; object-fit: contain; filter: drop-shadow(0 0 6px rgba(255,255,255,0.25));">
                        </a>

                        <!-- Social Icons -->
                        <div style="display: flex; align-items: center; justify-content: center; gap: 1rem;">
                            <a href="https://www.facebook.com/pcuupdatesmanila" target="_blank" rel="noopener noreferrer" aria-label="PCU Manila Facebook Page" style="width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; color: #cbd5e1; text-decoration: none;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                            </a>
                            <a href="https://www.youtube.com/@philippinechristianunivers1648" target="_blank" rel="noopener noreferrer" aria-label="PCU Manila YouTube Channel" style="width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; color: #cbd5e1; text-decoration: none;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.41 19.1C5.12 19.56 12 19.56 12 19.56s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.95-1.95 29 29 0 0 0 .46-5.34 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02" fill="white"/></svg>
                            </a>
                        </div>

                        <!-- Address -->
                        <p style="font-size: 0.875rem; color: #94a3b8; line-height: 1.6; margin: 0;">1648 Taft Avenue corner Pedro Gil St.,<br>Malate, Manila</p>

                        <!-- Divider -->
                        <div style="width: 60px; height: 1px; background: rgba(255,255,255,0.08);"></div>

                        <!-- Contact Us -->
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.25rem;">
                            <p style="font-size: 0.875rem; font-weight: 600; color: #ffffff; margin: 0;">Contact Us:</p>
                            <p style="font-size: 0.875rem; color: #94a3b8; line-height: 1.5; margin: 0;">PBX Phone Lines (connecting all<br>departments in the Manila Campus)</p>
                            <a href="tel:+6328248537082485390" style="font-size: 0.875rem; color: #38bdf8; text-decoration: underline; text-underline-offset: 3px;">Trunk Lines: 8248 - 5370 to 8248 - 5390</a>
                        </div>

                        <!-- Divider -->
                        <div style="width: 60px; height: 1px; background: rgba(255,255,255,0.08);"></div>

                        <!-- Working Hours -->
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.25rem;">
                            <p style="font-size: 0.875rem; font-weight: 600; color: #ffffff; margin: 0;">Working Hours</p>
                            <p style="font-size: 0.875rem; color: #94a3b8; margin: 0;">Monday - Saturday</p>
                            <p style="font-size: 0.875rem; color: #38bdf8; margin: 0;">8 am - 5 pm</p>
                            <p style="font-size: 0.875rem; color: #94a3b8; margin: 0;">Sunday - <span style="color: #fb7185;">Closed</span></p>
                        </div>

                        <!-- Divider -->
                        <div style="width: 60px; height: 1px; background: rgba(255,255,255,0.08);"></div>

                        <!-- Map -->
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; width: 100%;">
                            <p style="font-size: 0.875rem; font-weight: 600; color: #ffffff; margin: 0;">Our Location</p>
                            <div style="width: 100%; height: 200px; border-radius: 0.75rem; overflow: hidden; border: 1px solid #334155;">
                                <iframe
                                    src="https://www.openstreetmap.org/export/embed.html?bbox=120.9861%2C14.5726%2C120.9921%2C14.5786&amp;layer=mapnik&amp;marker=14.575619%2C120.989119"
                                    width="100%" height="100%"
                                    style="border: 0; display: block;"
                                    loading="lazy"
                                    title="Philippine Christian University — 1648 Taft Avenue, Malate, Manila"
                                    sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox">
                                </iframe>
                            </div>
                            <a href="https://www.openstreetmap.org/way/268207595#map=19/14.575619/120.989119"
                               target="_blank" rel="noopener noreferrer"
                               style="font-size: 0.75rem; color: #38bdf8; text-decoration: none;">
                                View larger map ↗
                            </a>
                        </div>

                        <!-- Privacy Policy -->
                        <a href="#" style="font-size: 0.875rem; font-weight: 600; color: #e2e8f0; text-decoration: none;">Privacy Policy</a>

                    </div>

                    <!-- Desktop Layout: 3-column grid (hidden on mobile/tablet) -->
                    <div class="hidden lg:grid lg:grid-cols-3 gap-10">

                        <!-- Left: Contact Info -->
                        <div class="flex flex-col gap-4">
                            <a href="#" class="text-sm font-semibold text-slate-200 hover:text-sky-300 transition-colors w-fit">Privacy Policy</a>
                            <div class="flex flex-col gap-2">
                                <p class="text-sm font-semibold text-white">Contact Us:</p>
                                <p class="text-sm text-slate-400 leading-relaxed">PBX Phone Lines (connecting all departments in the Manila Campus)</p>
                                <a href="tel:+6328248537082485390" class="text-sm text-sky-400 underline underline-offset-2 hover:text-sky-300 transition-colors w-fit">
                                    Trunk Lines: 8248 - 5370 to 8248 - 5390
                                </a>
                            </div>
                        </div>

                        <!-- Center: Logo, Socials, Address, Hours -->
                        <div class="flex flex-col items-center gap-4 text-center">
                            <a href="homepage.php"><img src="assets/images/pcu-logo.png" alt="Philippine Christian University Logo" class="w-16 h-16 object-contain" style="filter: drop-shadow(0 0 6px rgba(255,255,255,0.25));"></a>
                            <div class="flex items-center gap-5">
                                <a href="https://www.facebook.com/pcuupdatesmanila" target="_blank" rel="noopener noreferrer" aria-label="PCU Manila Facebook Page" class="text-slate-300 hover:text-blue-400 transition-colors duration-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>
                                    </svg>
                                </a>
                                <a href="https://www.youtube.com/@philippinechristianunivers1648" target="_blank" rel="noopener noreferrer" aria-label="PCU Manila YouTube Channel" class="text-slate-300 hover:text-red-500 transition-colors duration-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.41 19.1C5.12 19.56 12 19.56 12 19.56s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.95-1.95 29 29 0 0 0 .46-5.34 29 29 0 0 0-.46-5.33z"/>
                                        <polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02" fill="white"/>
                                    </svg>
                                </a>
                            </div>
                            <p class="text-sm text-slate-400 leading-relaxed">1648 Taft Avenue corner Pedro Gil St.,<br>Malate, Manila</p>
                            <div>
                                <p class="text-sm font-semibold text-white mb-1">Working Hours</p>
                                <p class="text-sm text-slate-400">Monday - Saturday<br><span class="text-sky-400">8 am - 5 pm</span></p>
                                <p class="text-sm text-slate-400 mt-1">Sunday - <span class="text-rose-400">Closed</span></p>
                            </div>
                        </div>

                        <!-- Right: OpenStreetMap -->
                        <div class="flex flex-col gap-3">
                            <p class="text-sm font-semibold text-white">Our Location</p>
                            <div class="rounded-xl overflow-hidden border border-slate-700 shadow-lg w-full" style="height: 200px;">
                                <iframe
                                    src="https://www.openstreetmap.org/export/embed.html?bbox=120.9861%2C14.5726%2C120.9921%2C14.5786&amp;layer=mapnik&amp;marker=14.575619%2C120.989119"
                                    width="100%" height="100%"
                                    style="border: 0; display: block;"
                                    loading="lazy"
                                    title="Philippine Christian University — 1648 Taft Avenue, Malate, Manila"
                                    sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox">
                                </iframe>
                            </div>
                            <a href="https://www.openstreetmap.org/way/268207595#map=19/14.575619/120.989119"
                               target="_blank" rel="noopener noreferrer"
                               class="text-xs text-sky-400 hover:text-sky-300 transition-colors text-center">
                                View larger map ↗
                            </a>
                        </div>

                    </div>

                </div>
            </div>

            <!-- Bottom copyright bar -->
            <div style="background: rgba(4, 8, 18, 0.96); border-top: 1px solid rgba(255,255,255,0.05);">
                <div class="w-full px-5 sm:px-10 lg:px-20 py-3 flex flex-col lg:flex-row items-center justify-center lg:justify-between gap-1.5 lg:gap-2 text-center">
                    <span class="text-xs font-medium text-slate-500 whitespace-nowrap">Made by: GROUP-INGS</span>
                    <span class="text-xs text-slate-400" style="text-align: center; width: 100%; display: block;">Copyright&copy; 2026. All Rights Reserved Philippine Christian University</span>
                    <span class="text-xs font-medium text-slate-500 whitespace-nowrap">Ver 1.25</span>
                </div>
            </div>
        </footer>
    </div>

    <!-- Contact Support Modal (Hidden by default) -->
    <div id="contactSupportModal" class="fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50 hidden" style="display: none;">
        <div class="bg-white rounded-2xl p-8 max-w-md w-full mx-4 shadow-2xl relative">
            <!-- Close button -->
            <button onclick="toggleContactSupport()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            
            <!-- Icon -->
            <div class="flex justify-center mb-6">
                <div class="w-16 h-16 bg-sky-100 rounded-full flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                    </svg>
                </div>
            </div>
            
            <!-- Title -->
            <h4 class="text-2xl font-bold text-slate-800 text-center mb-4">Contact Support</h4>
            
            <!-- Message -->
            <div class="bg-gradient-to-br from-sky-50 to-white rounded-xl p-6 border border-sky-100 mb-6">
                <p class="text-slate-700 text-center mb-4 leading-relaxed">
                    For any issues or concerns regarding your RFID account, please contact our support team:
                </p>
                <div class="flex items-center justify-center gap-2 text-sky-600 font-semibold">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <a href="mailto:rfid.support@pcu.edu.ph" class="hover:underline">rfid.support@pcu.edu.ph</a>
                </div>
            </div>
            
            <!-- Action button -->
            <button 
                onclick="window.open('https://mail.google.com/mail/?view=cm&fs=1&to=rfid.support@pcu.edu.ph', '_blank')" 
                class="w-full h-11 bg-sky-600 text-white text-base font-medium rounded-lg shadow-md transition duration-150 hover:bg-sky-700 active:transform active:scale-[0.98] flex items-center justify-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                Send Email
            </button>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="fixed bottom-4 right-4 space-y-2 z-50"></div>

    <script>
        // CSRF Token for JavaScript fetch requests
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        
        // User menu toggle
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenu = document.getElementById('user-menu');
        
        if (userMenuButton && userMenu) {
            userMenuButton.addEventListener('click', (event) => {
                event.stopPropagation(); // Prevent event from bubbling
                const isExpanded = userMenuButton.getAttribute('aria-expanded') === 'true';
                userMenuButton.setAttribute('aria-expanded', !isExpanded);
                userMenu.toggleAttribute('hidden');
            });

            // Close menu when clicking outside
            document.addEventListener('click', (event) => {
                if (!userMenuButton.contains(event.target) && !userMenu.contains(event.target)) {
                    userMenuButton.setAttribute('aria-expanded', 'false');
                    userMenu.hidden = true;
                }
            });
        }

        // Function to close user menu
        function closeUserMenu() {
            if (userMenuButton && userMenu) {
                userMenuButton.setAttribute('aria-expanded', 'false');
                userMenu.hidden = true;
            }
        }

        // Toggle user information
        function toggleInformation() {
            const infoSection = document.getElementById('userInformation');
            if (infoSection.classList.contains('hidden')) {
                infoSection.classList.remove('hidden');
                infoSection.style.maxHeight = infoSection.scrollHeight + 'px';
                infoSection.style.opacity = '1';
            } else {
                infoSection.style.maxHeight = '0px';
                infoSection.style.opacity = '0';
                setTimeout(() => infoSection.classList.add('hidden'), 300);
            }
        }

        // GSAP animations for page entry
        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.card-animate');
            gsap.set(cards, { y: 20, opacity: 0 });
            gsap.to(cards, {
                y: 0,
                opacity: 1,
                duration: 0.7,
                stagger: 0.08,
                ease: 'power2.out'
            });

            const blobs = document.querySelectorAll('.floating-blob');
            gsap.to(blobs, {
                scale: 1.05,
                duration: 6,
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut'
            });
        });

        // Toggle edit profile form
        function toggleEditProfile() {
            const editForm = document.getElementById('editProfileForm');
            const modalContent = editForm.querySelector('div');
            
            if (editForm.classList.contains('hidden')) {
                editForm.classList.remove('hidden');
                editForm.style.display = 'flex';
                setTimeout(() => {
                    modalContent.classList.add('modal-show');
                }, 10);
            } else {
                modalContent.classList.remove('modal-show');
                setTimeout(() => {
                    editForm.classList.add('hidden');
                    editForm.style.display = 'none';
                }, 300);
            }
        }

        // Toggle contact support modal
        function toggleContactSupport() {
            const modal = document.getElementById('contactSupportModal');
            const modalContent = modal.querySelector('div');
            
            if (modal.classList.contains('hidden')) {
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
                setTimeout(() => {
                    modalContent.classList.add('modal-show');
                }, 10);
            } else {
                modalContent.classList.remove('modal-show');
                setTimeout(() => {
                    modal.classList.add('hidden');
                    modal.style.display = 'none';
                }, 300);
            }
        }
    </script>
    <script src="assets/js/app.js"></script>
</body>
</html>

