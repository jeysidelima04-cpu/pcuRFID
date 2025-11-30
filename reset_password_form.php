<?php 
require_once __DIR__ . '/db.php';

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Verify token
$token = $_GET['token'] ?? '';
if (empty($token)) {
    header('Location: login.php');
    exit;
}

try {
    $pdo = pdo();
    
    // Debug: Check current server time
    error_log('Server time: ' . date('Y-m-d H:i:s'));
    
    // Check if token is valid and not expired
    $stmt = $pdo->prepare('
        SELECT pr.*, u.email, u.name 
        FROM pcu_rfid2_password_resets pr 
        JOIN users u ON pr.user_id = u.id 
        WHERE pr.token = ? 
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        error_log('Token not found in database: ' . $token);
        header('Location: forgot_password.php?error=' . urlencode('Invalid reset link. Please request a new one.'));
        exit;
    }

    // Separate expiry check for debugging
    if ($reset['used'] == 1) {
        error_log('Token already used: ' . $token);
        header('Location: forgot_password.php?error=' . urlencode('This reset link has already been used. Please request a new one.'));
        exit;
    }

    if (strtotime($reset['expires_at']) < time()) {
        error_log('Token expired at: ' . $reset['expires_at'] . ', current time: ' . date('Y-m-d H:i:s'));
        header('Location: forgot_password.php?error=' . urlencode('This reset link has expired. Please request a new one.'));
        exit;
    }

} catch (Exception $e) {
    error_log('Password reset error: ' . $e->getMessage());
    header('Location: forgot_password.php?error=' . urlencode('An error occurred. Please try again later.'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>PCU RFID | Reset Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style type="text/tailwindcss">
        .bg-pcu {
            position: relative;
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
        }
    </style>
</head>
<body class="text-slate-800 bg-pcu min-h-screen">
    <div class="min-h-screen flex items-center justify-center p-4 relative z-10">
        <div class="w-full max-w-md bg-white/90 shadow-2xl rounded-2xl p-8 transition-all fade-in">
            <div class="mb-8 text-center">
                <a href="login.php" class="inline-block hover:opacity-80 transition-opacity">
                    <img src="pcu-logo.png" alt="PCU Logo" class="w-24 h-24 mx-auto mb-6">
                </a>
                <h1 class="text-3xl font-semibold text-sky-700 mb-2">Set New Password</h1>
                <p class="text-base text-slate-600">Please enter your new password</p>
            </div>

            <?php if (isset($_GET['error'])): ?>
                <div class="mb-6 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-4 shadow-sm"><?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>

            <form action="reset_password.php" method="POST" class="space-y-6" id="resetPasswordForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <?php 
                // Debug output
                error_log('Form token value: ' . $token);
                ?>

                <div class="space-y-2">
                    <label class="block text-base font-medium text-slate-700">Old Password</label>
                    <input 
                        type="password" 
                        name="old_password" 
                        required 
                        class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 placeholder-slate-400
                               shadow-sm transition duration-150
                               hover:border-slate-300
                               focus:border-sky-500 focus:ring-4 focus:ring-sky-100 focus:outline-none
                               invalid:border-red-300 invalid:text-red-600
                               invalid:focus:border-red-500 invalid:focus:ring-red-100" 
                        placeholder="Enter your current password"
                    >
                </div>

                <div class="space-y-2">
                    <label class="block text-base font-medium text-slate-700">New Password</label>
                    <input 
                        type="password" 
                        name="password" 
                        required 
                        minlength="8"
                        pattern="^(?=.*[A-Z])(?=.*[!@#$%^&*]).{8,}$"
                        class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 placeholder-slate-400
                               shadow-sm transition duration-150
                               hover:border-slate-300
                               focus:border-sky-500 focus:ring-4 focus:ring-sky-100 focus:outline-none
                               invalid:border-red-300 invalid:text-red-600
                               invalid:focus:border-red-500 invalid:focus:ring-red-100" 
                        placeholder="••••••••"
                    >
                    <div class="text-sm text-slate-500">
                        Password must contain:
                        <ul class="list-disc ml-5 mt-1">
                            <li>At least 8 characters</li>
                            <li>One uppercase letter</li>
                            <li>One special character (!@#$%^&*)</li>
                        </ul>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-base font-medium text-slate-700">Confirm New Password</label>
                    <input 
                        type="password" 
                        name="confirm_password" 
                        required 
                        minlength="8"
                        pattern="^(?=.*[A-Z])(?=.*[!@#$%^&*]).{8,}$"
                        class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 placeholder-slate-400
                               shadow-sm transition duration-150
                               hover:border-slate-300
                               focus:border-sky-500 focus:ring-4 focus:ring-sky-100 focus:outline-none
                               invalid:border-red-300 invalid:text-red-600
                               invalid:focus:border-red-500 invalid:focus:ring-red-100" 
                        placeholder="••••••••"
                    >
                </div>

                <button 
                    type="submit" 
                    class="w-full h-11 bg-sky-600 text-white text-base font-medium rounded-lg
                           shadow-md shadow-sky-100 transition duration-150
                           hover:bg-sky-700 hover:shadow-lg hover:shadow-sky-100
                           active:transform active:scale-[0.98]"
                >
                    Reset Password
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-500">
                Remember your password?
                <a href="login.php" class="text-sky-700 hover:underline">Sign in</a>
            </p>
        </div>
    </div>

    <div id="toast-container" class="fixed bottom-4 right-4 space-y-2 z-50"></div>
    <script src="assets/js/app.js"></script>
</body>
</html>