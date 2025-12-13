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
    
} catch (Exception $e) {
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
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Heroicons -->
    <script src="https://unpkg.com/@heroicons/v2/24/outline/esm/index.js"></script>
    <style>
        .bg-pcu {
            position: relative;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .bg-pcu::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url('pcu-building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            filter: blur(2px);
            -webkit-filter: blur(2px);
            z-index: -1;
            opacity: 0.9;
        }
        
        /* Mobile Responsive Styles */
        @media (max-width: 768px) {
            /* Navbar - keep logo on left */
            nav .max-w-7xl {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }
            
            /* Adjust navbar height and vertical alignment */
            nav .flex.justify-between {
                justify-content: space-between !important;
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
            }
            
            /* Move logo down slightly */
            nav .flex.items-center:first-child {
                padding-top: 0.25rem !important;
            }
            
            /* Move user menu down slightly */
            nav .flex.items-center:last-child {
                position: relative !important;
                padding-top: 0.25rem !important;
            }
            
            /* Information card content - left aligned */
            .bg-gradient-to-br .flex.items-center {
                align-items: flex-start !important;
            }
            
            .bg-gradient-to-br .flex.items-center > div:last-child {
                text-align: left !important;
                width: 100% !important;
            }
            
            /* Ensure card text is left-aligned */
            .bg-gradient-to-br .text-sm,
            .bg-gradient-to-br .text-lg {
                text-align: left !important;
            }
            
            /* Ensure card labels and values are properly aligned */
            .bg-gradient-to-br .font-medium.text-slate-500,
            .bg-gradient-to-br .font-semibold.text-slate-700 {
                display: block !important;
                text-align: left !important;
            }
            
            /* Fix icon alignment in cards */
            .bg-gradient-to-br .space-x-3 {
                align-items: center !important;
            }
            
            /* Violations card special handling */
            .bg-gradient-to-br .flex-grow {
                width: 100% !important;
            }
            
            .bg-gradient-to-br .flex.items-center.justify-between {
                align-items: flex-start !important;
                flex-direction: column !important;
                gap: 0.5rem !important;
            }
            
            /* Profile section - center everything */
            .flex.flex-col.items-center {
                align-items: center !important;
                text-align: center !important;
            }
            
            .profile-container {
                 display: flex !important;
                justify-content: center !important;
                align-items: center !important;
                width: 100% !important;
            }
            
            /* Profile picture centered */
            .profile-container .w-32.h-32 {
                width: 5rem !important;
                height: 5rem !important;
                font-size: 2.5rem !important;
                margin-left: auto !important;
                margin-right: auto !important;
            }
            
            /* Welcome message centered */
            .text-center {
                text-align: center !important;
                margin-left: auto !important;
                margin-right: auto !important;
            }
            
            .text-3xl {
                font-size: 1.5rem !important;
            }
            
            .text-lg {
                font-size: 1rem !important;
            }
            
            /* View Information button centered */
            .flex.justify-center {
                justify-content: center !important;
            }
            
            .hover-button {
                padding: 0.75rem 1.5rem !important;
                font-size: 0.875rem !important;
                margin-left: auto !important;
                margin-right: auto !important;
            }
            
            /* Information cards grid - stack vertically */
            .grid.md\\:grid-cols-2 {
                grid-template-columns: 1fr !important;
            }
            
            /* Card adjustments */
            .bg-gradient-to-br {
                padding: 0.75rem !important;
            }
            
            /* Reduce spacing */
            .space-y-8 > * + * {
                margin-top: 1.5rem !important;
            }
            
            .py-12 {
                padding-top: 2rem !important;
                padding-bottom: 2rem !important;
            }
            
            /* Main content padding */
            main {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
                padding-top: 5rem !important;
            }
            
            /* Information section */
            #userInformation {
                padding: 1.5rem !important;
                margin-left: 0.5rem !important;
                margin-right: 0.5rem !important;
                max-width: 100% !important;
            }
            
            /* Center everything properly */
            .max-w-2xl {
                max-width: 100% !important;
            }
            
            .max-w-xs {
                max-width: 20rem !important;
            }
            
            /* Adjust card text sizes */
            .text-2xl {
                font-size: 1.25rem !important;
            }
            
            .h-1.w-20 {
                width: 3rem !important;
            }
        }
        
        @media (max-width: 480px) {
            /* Extra small devices */
            .profile-container .w-32.h-32 {
                width: 4rem !important;
                height: 4rem !important;
                font-size: 2rem !important;
            }
            
            #userInformation {
                padding: 1rem !important;
            }
            
            .gap-6 {
                gap: 0.75rem !important;
            }
            
            .max-w-xs {
                max-width: 100% !important;
            }
        }
        
        /* Button Animations */
        .hover-button {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .hover-button:hover {
            transform: translateY(-2px);
        }
        .hover-button:active {
            transform: translateY(0);
        }
        
        /* Profile Picture Animation */
        .profile-container {
            position: relative;
            transition: transform 0.3s ease;
        }
        .profile-container:hover {
            transform: scale(1.05);
        }
        .profile-container::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 50%;
            border: 3px solid #0284c7;
            opacity: 0;
            transition: all 0.3s ease;
        }
        .profile-container:hover::after {
            opacity: 1;
            transform: scale(1.1);
        }
        
        /* Sidebar Animation */
        .sidebar-button {
            transition: all 0.2s ease;
            justify-content: center !important;
            align-items: center !important;
        }
        .sidebar-button:hover {
            background-color: #0284c7;
            color: white;
        }
        .sidebar-button:focus,
        .sidebar-button[aria-expanded="true"] {
            background-color: #0284c7;
            color: white;
        }
        
        /* Profile Menu Animation */
        .profile-menu {
            transform-origin: top right;
            transition: all 0.2s ease-out;
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
        
        /* Logo vertical alignment */
        @media (max-width: 768px) {
            nav .flex.items-center a img {
                margin-top: -0.125rem !important;
            }
        }
        
        /* Modal fade-in animation */
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        /* Success animation */
        @keyframes successPulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
        
        .modal-show {
            animation: modalFadeIn 0.3s ease-out;
        }
        
        .success-animation {
            animation: successPulse 0.5s ease-in-out;
        }
        
        /* Page reload transition */
        .page-reload-transition {
            transition: opacity 0.5s ease-out, transform 0.5s ease-out;
            opacity: 0;
            transform: scale(0.95);
        }
        
        /* Checkmark animation */
        .checkmark-circle {
            stroke-dasharray: 166;
            stroke-dashoffset: 166;
            stroke-width: 2;
            stroke-miterlimit: 10;
            stroke: #10b981;
            fill: none;
            animation: stroke 0.6s cubic-bezier(0.65, 0, 0.45, 1) forwards;
        }
        
        .checkmark {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: block;
            stroke-width: 3;
            stroke: #10b981;
            stroke-miterlimit: 10;
            margin: 0 auto;
            box-shadow: inset 0px 0px 0px #10b981;
            animation: fill 0.4s ease-in-out 0.4s forwards, scale 0.3s ease-in-out 0.9s both;
        }
        
        .checkmark-check {
            transform-origin: 50% 50%;
            stroke-dasharray: 48;
            stroke-dashoffset: 48;
            animation: stroke 0.3s cubic-bezier(0.65, 0, 0.45, 1) 0.8s forwards;
        }
        
        @keyframes stroke {
            100% {
                stroke-dashoffset: 0;
            }
        }
        
        @keyframes scale {
            0%, 100% {
                transform: none;
            }
            50% {
                transform: scale3d(1.1, 1.1, 1);
            }
        }
        
        @keyframes fill {
            100% {
                box-shadow: inset 0px 0px 0px 40px #10b981;
            }
        }
        
        .success-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 60;
            opacity: 0;
            animation: fadeIn 0.3s ease-out forwards;
        }
        
        .success-text {
            color: white;
            font-size: 1.5rem;
            font-weight: 600;
            margin-top: 1.5rem;
            opacity: 0;
            animation: fadeIn 0.4s ease-out 0.8s forwards;
        }
        
        @keyframes fadeIn {
            to {
                opacity: 1;
            }
        }
    </style>
</head>
<body class="text-slate-800">
    <div class="bg-pcu min-h-screen">
        <!-- Navbar -->
        <nav class="bg-white/90 backdrop-blur-sm border-b border-slate-200 fixed w-full top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <!-- Logo -->
                    <div class="flex items-center">
                        <a href="homepage.php" class="flex items-center hover:opacity-80 transition-opacity">
                            <img src="pcu-logo.png" alt="PCU Logo" class="h-10 w-10">
                            <span class="ml-2 text-xl font-semibold text-sky-700">GateWatch</span>
                        </a>
                    </div>
                    
                    <!-- User Menu -->
                    <div class="flex items-center">
                        <div class="relative ml-3">
                            <button type="button" 
                                    class="sidebar-button flex items-center p-2 rounded-full text-slate-700 hover:text-white focus:outline-none focus:ring-2 focus:ring-sky-500"
                                    id="user-menu-button"
                                    aria-expanded="false"
                                    aria-haspopup="true">
                                <span class="sr-only">Open user menu</span>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </button>

                            <!-- Dropdown menu -->
                            <div class="profile-menu absolute right-0 mt-2 w-64 rounded-xl bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 hidden" 
                                 role="menu" 
                                 id="user-menu"
                                 hidden>
                                <div class="px-4 py-2 text-sm text-slate-500">
                                    <div class="mb-1">Signed in as</div>
                                    <div class="font-medium text-slate-700 break-words"><?= htmlspecialchars($user['email']) ?></div>
                                </div>
                                <div class="border-t border-slate-200"></div>
                                <a href="digital_id.php" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 transition-colors flex items-center gap-2" role="menuitem">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" />
                                    </svg>
                                    Digital ID
                                </a>
                                <button type="button" onclick="toggleContactSupport(); closeUserMenu();" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 transition-colors flex items-center gap-2" role="menuitem">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                    </svg>
                                    Contact Support
                                </button>
                                <div class="border-t border-slate-200"></div>
                                <form action="auth.php" method="POST" class="block">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="logout">
                                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-700 hover:bg-red-50 transition-colors" role="menuitem">
                                        Sign out
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-20 pb-12">
            <!-- Profile Section -->
            <div class="flex flex-col items-center justify-center space-y-8 py-12">
                <!-- Profile Picture -->
                <?php
                    // Get the first letter of the user's name
                    $firstLetter = strtoupper(substr($user['name'], 0, 1));
                    
                    // Generate a consistent color based on the name
                    $colors = ['bg-blue-500', 'bg-green-500', 'bg-yellow-500', 'bg-red-500', 'bg-purple-500', 'bg-pink-500'];
                    $colorIndex = ord($firstLetter) % count($colors);
                    $bgColor = $colors[$colorIndex];
                    
                    // Check if user has uploaded profile picture
                    $hasProfilePicture = !empty($user['profile_picture']);
                    $profilePictureUrl = $hasProfilePicture ? 'assets/images/profiles/' . htmlspecialchars($user['profile_picture']) : '';
                ?>
                <div class="profile-container relative group">
                    <?php if ($hasProfilePicture): ?>
                        <div id="profilePictureDisplay" class="w-32 h-32 rounded-full border-4 border-white shadow-xl overflow-hidden bg-slate-100">
                            <img src="<?= $profilePictureUrl ?>" alt="Profile Picture" class="w-full h-full object-cover">
                        </div>
                    <?php else: ?>
                        <div id="profilePictureDisplay" class="w-32 h-32 rounded-full <?= $bgColor ?> border-4 border-white shadow-xl flex items-center justify-center text-white text-6xl font-bold">
                            <?= htmlspecialchars($firstLetter) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Welcome Message -->
                <div class="text-center bg-white/90 backdrop-blur-sm px-8 py-4 rounded-2xl shadow-lg">
                    <h2 class="text-3xl font-bold text-sky-700 mb-1">Welcome, <?= htmlspecialchars($user['name']) ?></h2>
                    <p class="text-lg text-slate-700 font-medium">Student</p>
                </div>

                <!-- Action Button -->
                <div class="flex justify-center w-full px-4 mb-8">
                    <button onclick="toggleInformation()" class="hover-button w-full max-w-xs bg-white hover:bg-slate-50 text-slate-700 rounded-xl px-6 py-3 font-medium shadow-lg border border-slate-200 flex items-center justify-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                        </svg>
                        View Information
                    </button>
                </div>

                <!-- User Information Section (Hidden by default) -->
                <div id="userInformation" class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-xl p-8 max-w-2xl mx-auto hidden">
                    <!-- Profile Information Header -->
                    <div class="text-center mb-8">
                        <h3 class="text-2xl font-bold text-sky-700">Student Information</h3>
                        <div class="h-1 w-20 bg-sky-700 mx-auto mt-2 rounded-full"></div>
                    </div>

                    <!-- Profile Information Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Student ID Card -->
                        <div class="bg-gradient-to-br from-sky-50 to-white rounded-xl p-4 border border-sky-100 shadow-sm">
                            <div class="flex items-center space-x-3">
                                <div class="p-2 bg-sky-100 rounded-lg">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-sky-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-slate-500">Student ID</div>
                                    <div class="text-lg font-semibold text-slate-700"><?= htmlspecialchars($user['student_id']) ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Name Card -->
                        <div class="bg-gradient-to-br from-sky-50 to-white rounded-xl p-4 border border-sky-100 shadow-sm">
                            <div class="flex items-center space-x-3">
                                <div class="p-2 bg-sky-100 rounded-lg">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-sky-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-slate-500">Full Name</div>
                                    <div class="text-lg font-semibold text-slate-700"><?= htmlspecialchars($user['name']) ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Role Card -->
                        <div class="bg-gradient-to-br from-sky-50 to-white rounded-xl p-4 border border-sky-100 shadow-sm">
                            <div class="flex items-center space-x-3">
                                <div class="p-2 bg-sky-100 rounded-lg">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-sky-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-slate-500">Role</div>
                                    <div class="text-lg font-semibold text-slate-700"><?= htmlspecialchars($user['role']) ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Status Card -->
                        <div class="bg-gradient-to-br from-sky-50 to-white rounded-xl p-4 border border-sky-100 shadow-sm">
                            <div class="flex items-center space-x-3">
                                <div class="p-2 bg-sky-100 rounded-lg">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-sky-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-slate-500">Status</div>
                                    <div class="text-lg">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium <?= $user['status'] === 'Active' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' ?>">
                                            <?= htmlspecialchars($user['status']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- RFID Card Status -->
                        <div class="bg-gradient-to-br from-<?= !empty($user['rfid_uid']) ? 'green' : 'amber' ?>-50 to-white rounded-xl p-4 border border-<?= !empty($user['rfid_uid']) ? 'green' : 'amber' ?>-100 shadow-sm">
                            <div class="flex items-center space-x-3">
                                <div class="p-2 bg-<?= !empty($user['rfid_uid']) ? 'green' : 'amber' ?>-100 rounded-lg">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-<?= !empty($user['rfid_uid']) ? 'green' : 'amber' ?>-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-slate-500">RFID Card</div>
                                    <div class="text-lg">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium <?= !empty($user['rfid_uid']) ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>">
                                            <?= !empty($user['rfid_uid']) ? 'Active' : 'Not Active' ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Strike/Violation Card -->
                        <div class="bg-gradient-to-br from-rose-50 to-white rounded-xl p-4 border border-rose-100 shadow-sm">
                            <div class="flex items-center space-x-3">
                                <div class="p-2 bg-rose-100 rounded-lg">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                    </svg>
                                </div>
                                <div class="flex-grow">
                                    <div class="text-sm font-medium text-slate-500">ID Violations</div>
                                    <div class="text-lg font-semibold text-slate-700">
                                        <?= $user['violation_count'] ?? 0 ?> Violation<?= ($user['violation_count'] ?? 0) !== 1 ? 's' : '' ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
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

        // Function to close user menu
        function closeUserMenu() {
            userMenuButton.setAttribute('aria-expanded', 'false');
            userMenu.hidden = true;
        }

        // Toggle user information
        function toggleInformation() {
            const infoSection = document.getElementById('userInformation');
            if (infoSection.classList.contains('hidden')) {
                infoSection.classList.remove('hidden');
                infoSection.classList.add('animate-fade-in');
            } else {
                infoSection.classList.add('hidden');
                infoSection.classList.remove('animate-fade-in');
            }
        }

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

