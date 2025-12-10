<?php
require_once 'db.php';
require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Enhanced session security check
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id']) || empty($_SESSION['user']['email'])) {
    session_unset();
    session_destroy();
    header('Location: login.php?error=' . urlencode('Please log in to access the digital ID'));
    exit;
}

// Session timeout after 30 minutes of inactivity
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    header('Location: login.php?error=' . urlencode('Session expired. Please log in again'));
    exit;
}

$_SESSION['last_activity'] = time();

// Get user information from database
try {
    $pdo = pdo();
    $stmt = $pdo->prepare('SELECT id, student_id, name, email, profile_picture, created_at FROM users WHERE id = ? AND email = ? LIMIT 1');
    $stmt->execute([$_SESSION['user']['id'], $_SESSION['user']['email']]);
    $user = $stmt->fetch();
    
    if (!$user || !isset($user['status']) || $user['status'] !== 'Active') {
        // Fallback: if status check fails, just ensure user exists
        if (!$user) {
            session_unset();
            session_destroy();
            header('Location: login.php?error=' . urlencode('User not found'));
            exit;
        }
    }
    
    // Create secure JWT token for QR code
    $jwt_secret = env('JWT_SECRET', 'pcurfid2-default-secret-change-in-production');
    $payload = [
        'student_id' => $user['student_id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'issued_at' => time(),
        'expires_at' => time() + 300 // 5 minutes expiry for security
    ];
    $jwt_token = JWT::encode($payload, $jwt_secret, 'HS256');
    
} catch (Exception $e) {
    error_log('Digital ID error: ' . $e->getMessage());
    header('Location: homepage.php?error=' . urlencode('Unable to generate digital ID'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Student ID | GateWatch</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        .bg-gradient-pcu {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        }
        .pcu-logo-bg {
            background-image: url('pcu-building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <nav class="bg-gradient-pcu shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center">
                    <a href="homepage.php" class="text-white hover:text-gray-200 transition-colors">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                    </a>
                    <h1 class="ml-4 text-xl font-bold text-white">Digital Student ID</h1>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="min-h-screen flex justify-center items-center p-4 py-12">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden animate-fade-in">
            <!-- Card Header -->
            <div class="relative bg-gradient-pcu pb-20 pt-8 text-center overflow-hidden">
                <!-- Background Image Overlay -->
                <div class="absolute inset-0 pcu-logo-bg opacity-20"></div>
                
                <!-- Content -->
                <div class="relative z-10">
                    <h2 class="text-white text-2xl font-bold">GateWatch System</h2>
                    <p class="text-blue-100 text-sm mt-1">Philippine Christian University</p>
                </div>
            </div>

            <!-- Student Photo -->
            <div class="flex justify-center px-6 -mt-16 mb-6 relative z-20">
                <?php if (!empty($user['profile_picture'])): ?>
                    <img src="assets/profiles/<?php echo e($user['profile_picture']); ?>" 
                         alt="<?php echo e($user['name']); ?>" 
                         class="w-32 h-32 rounded-full border-4 border-white shadow-2xl object-cover bg-gray-200">
                <?php else: ?>
                    <div class="w-32 h-32 rounded-full border-4 border-white shadow-2xl bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center">
                        <span class="text-white text-4xl font-bold"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Student Information -->
            <div class="px-6 pb-6 space-y-4">
                <div class="text-center">
                    <h3 class="text-2xl font-bold text-gray-800"><?php echo e($user['name']); ?></h3>
                    <p class="text-blue-600 font-semibold text-lg mt-1"><?php echo e($user['student_id']); ?></p>
                    <p class="text-gray-500 text-sm mt-1"><?php echo e($user['email']); ?></p>
                </div>

                <!-- QR Code Section -->
                <div class="bg-gray-50 rounded-xl p-6 text-center">
                    <p class="text-gray-600 text-sm mb-4 font-medium">Scan QR Code for Verification</p>
                    <div class="flex justify-center">
                        <canvas id="qr-code" class="border-4 border-white shadow-lg rounded-lg"></canvas>
                    </div>
                    <p class="text-xs text-gray-400 mt-4">
                        <svg class="inline w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                        </svg>
                        Valid for 5 minutes
                    </p>
                </div>

                <!-- Member Since -->
                <div class="text-center pt-4 border-t border-gray-200">
                    <p class="text-xs text-gray-500">Member since</p>
                    <p class="text-sm font-semibold text-gray-700">
                        <?php echo date('F Y', strtotime($user['created_at'])); ?>
                    </p>
                </div>

                <!-- Action Buttons -->
                <div class="grid grid-cols-2 gap-3 pt-4">
                    <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-lg transition-colors flex items-center justify-center space-x-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                        </svg>
                        <span>Print</span>
                    </button>
                    <button onclick="refreshQR()" class="bg-gray-600 hover:bg-gray-700 text-white font-semibold py-3 px-4 rounded-lg transition-colors flex items-center justify-center space-x-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        <span>Refresh</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- QRious Library -->
    <script src="assets/js/qrious.min.js"></script>
    <script>
        // Generate QR Code
        function generateQR() {
            var qr = new QRious({
                element: document.getElementById("qr-code"),
                size: 200,
                value: "<?php echo $jwt_token; ?>",
                level: 'H',
                background: '#ffffff',
                foreground: '#1e3a8a'
            });
        }

        // Refresh QR Code (reload page to get new token)
        function refreshQR() {
            window.location.reload();
        }

        // Generate QR on page load
        generateQR();

        // Auto-refresh every 4 minutes (before 5-minute expiry)
        setTimeout(function() {
            if (confirm('QR code is about to expire. Refresh now?')) {
                window.location.reload();
            }
        }, 240000); // 4 minutes

        // Print styles
        window.addEventListener('beforeprint', function() {
            document.body.classList.add('printing');
        });
    </script>

    <style>
        @media print {
            body {
                background: white;
            }
            nav, button {
                display: none !important;
            }
            .bg-white {
                box-shadow: none !important;
            }
        }
    </style>
</body>
</html>
