<?php
require_once __DIR__ . '/../db.php';

// If already logged in, redirect to homepage
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: homepage.php');
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Verify credentials (using constants for demo - in production, use database)
    if ($username === './pcuadmin' && $password === './@pcuAdmin') {
        $_SESSION['user_id'] = 'admin';
        $_SESSION['role'] = 'Admin';
        $_SESSION['admin_logged_in'] = true;
        header('Location: homepage.php');
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PCU RFID Admin | Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="../assets/css/styles.css">
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
            background-image: url('../pcu-building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            filter: blur(2px);
            -webkit-filter: blur(2px);
            z-index: -1;
        }
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
    </style>
</head>
<body class="bg-pcu min-h-screen">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-white/95 rounded-2xl shadow-2xl p-8 fade-in">
            <div class="text-center mb-8">
                <img src="../pcu-logo.png" alt="PCU Logo" class="w-24 h-24 mx-auto mb-6">
                <h1 class="text-3xl font-semibold text-[#0056b3] mb-2">Admin Login</h1>
                <p class="text-slate-600">RFID System Administration</p>
            </div>

            <?php if (isset($error)): ?>
                <div class="mb-6 p-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm fade-in">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-slate-700">Username</label>
                    <input 
                        type="text" 
                        name="username" 
                        required 
                        class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 focus:border-[#0056b3] focus:ring-4 focus:ring-blue-100 transition-all"
                        placeholder="Enter admin username"
                    >
                </div>

                <div class="space-y-2">
                    <label class="block text-sm font-medium text-slate-700">Password</label>
                    <input 
                        type="password" 
                        name="password" 
                        required 
                        class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 focus:border-[#0056b3] focus:ring-4 focus:ring-blue-100 transition-all"
                        placeholder="Enter admin password"
                    >
                </div>

                <button 
                    type="submit" 
                    class="w-full h-11 bg-[#0056b3] text-white font-medium rounded-lg btn-hover transition-all"
                >
                    Sign In
                </button>
            </form>
        </div>
    </div>

    <script>
        // Add loading state to form submission
        document.querySelector('form').addEventListener('submit', function(e) {
            const button = this.querySelector('button[type="submit"]');
            button.disabled = true;
            button.innerHTML = `
                <svg class="animate-spin h-5 w-5 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            `;
        });
    </script>
</body>
</html>