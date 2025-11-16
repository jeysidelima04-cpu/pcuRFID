<?php
require_once 'db.php';
verify_csrf();

$email = $_GET['email'] ?? '';
$info = $_GET['info'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PCU RFID | Verify 2FA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
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
            background-image: url('/pcuRFID2/pcu-building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            filter: blur(2px);
            -webkit-filter: blur(2px);
            z-index: -1;
        }
        .code-input {
            letter-spacing: 0.5em;
            text-align: center;
            font-size: 1.5em;
        }
        /* Success Animation Styles */
        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(4px);
            z-index: 50;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.5s ease-in-out, visibility 0.5s;
        }
        .success-message {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            z-index: 51;
            text-align: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .success-icon {
            display: inline-block;
            border-radius: 50%;
            background: #10B981;
            padding: 2rem;
            margin-bottom: 1.5rem;
            transform: scale(0);
            transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .success-icon svg {
            width: 4rem;
            height: 4rem;
            stroke: white;
            stroke-width: 2;
        }
        .success-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        .success-message.show {
            opacity: 1;
            visibility: visible;
            transform: translate(-50%, -50%) scale(1);
        }
        .success-message.show .success-icon {
            transform: scale(1);
        }
        @keyframes checkmark {
            0% {
                stroke-dashoffset: 100;
            }
            100% {
                stroke-dashoffset: 0;
            }
        }
        .checkmark-path {
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
        }
        .success-message.show .checkmark-path {
            animation: checkmark 1s ease-in-out forwards;
            animation-delay: 0.5s;
        }
    </style>
</head>
<body class="text-slate-800">
    <div class="bg-pcu min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-lg bg-white/90 shadow-2xl rounded-2xl p-8 transition-all fade-in">
            <!-- Logo -->
            <div class="mb-8 text-center">
                <a href="login.php" class="inline-block hover:opacity-80 transition-opacity">
                    <img src="pcu-logo.png" alt="PCU Logo" class="w-24 h-24 mx-auto mb-6">
                </a>
                <h2 class="text-3xl font-semibold text-sky-700 mb-2">
                    Verify Your Account
                </h2>
                <p class="text-base text-slate-600">
                    Enter the verification code sent to<br>
                    <span class="font-medium text-sky-600"><?= htmlspecialchars($email) ?></span>
                </p>
            </div>

            <!-- Error/Info Messages -->
            <?php if ($error): ?>
            <div class="mb-6 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-4 shadow-sm">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <?php if ($info): ?>
            <div class="mb-6 text-sm text-blue-700 text-center">
                <?= htmlspecialchars($info) ?>
            </div>
            <?php endif; ?>

            <!-- 2FA Form -->
            <form action="auth.php" method="POST" class="space-y-6">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="verify_2fa">
                <div class="space-y-2">
                    <div class="relative">
                        <label for="code" class="text-sm text-slate-600">Enter 6-digit verification code</label>
                        <input id="code" name="code" type="text" required
                            class="code-input block w-full px-4 py-4 rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500 focus:border-transparent transition-all bg-white/50 backdrop-blur-sm"
                            placeholder="000000"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            inputmode="numeric"
                            autocomplete="one-time-code">
                        <p class="mt-2 text-sm text-slate-500">Check your email for the verification code</p>
                    </div>
                </div>

                <div>
                    <button type="submit"
                        class="w-full px-8 py-4 text-white bg-sky-600 hover:bg-sky-700 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 disabled:opacity-50">
                        Verify Code
                    </button>
                </div>
            </form>

            <!-- Resend Form -->
            <div class="mt-6 text-center">
                <form id="resendForm" action="auth.php" method="POST" class="inline">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="resend_2fa">
                    <button type="submit" id="resendButton" 
                        class="text-sm text-sky-600 hover:text-sky-700 disabled:text-gray-400 disabled:cursor-not-allowed transition-colors">
                        Resend code
                    </button>
                </form>
                <span id="timer" class="text-sm text-gray-500 ml-2"></span>
            </div>
        </div>
    </div>

    <!-- Success Animation Overlay -->
    <div class="success-overlay" id="successOverlay">
        <div class="success-message" id="successMessage">
            <div class="success-icon">
                <svg viewBox="0 0 50 50">
                    <path class="checkmark-path" fill="none" d="M10,25 L22,37 L40,13" />
                </svg>
            </div>
            <h2 class="text-3xl font-semibold text-sky-700 mb-3">Account Verified!</h2>
            <p class="text-slate-600">Redirecting to login page...</p>
        </div>
    </div>

    <script>
        let timerInterval;
        const resendButton = document.getElementById('resendButton');
        const timerDisplay = document.getElementById('timer');

        function startTimer(duration) {
            resendButton.disabled = true;
            let timeLeft = duration;

            timerInterval = setInterval(() => {
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                timerDisplay.textContent = `(${minutes}:${seconds.toString().padStart(2, '0')})`;

                if (--timeLeft < 0) {
                    clearInterval(timerInterval);
                    timerDisplay.textContent = '';
                    resendButton.disabled = false;
                }
            }, 1000);
        }

        // Start timer if info message contains "sent"
        const infoMessage = '<?= addslashes($info) ?>';
        if (infoMessage.toLowerCase().includes('sent')) {
            startTimer(60);
        }

        // Handle resend form submission
        document.getElementById('resendForm').addEventListener('submit', () => {
            startTimer(60);
        });

        // Format code input
        const codeInput = document.getElementById('code');
        codeInput.addEventListener('input', (e) => {
            e.target.value = e.target.value.replace(/[^0-9]/g, '').slice(0, 6);
        });

        // Handle form submission and success animation
        document.querySelector('form[action="auth.php"]').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            
            try {
                const response = await fetch('auth.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.text();
                
                if (data.includes('Account verified')) {
                    // Show success animation
                    const overlay = document.getElementById('successOverlay');
                    const message = document.getElementById('successMessage');
                    
                    overlay.classList.add('show');
                    message.classList.add('show');
                    
                    // Wait for animation to complete then redirect
                    setTimeout(() => {
                        window.location.href = 'login.php?toast=Account verified. You can now log in.';
                    }, 2500);
                } else {
                    // If error, submit form normally
                    e.target.submit();
                }
            } catch (error) {
                // If fetch fails, submit form normally
                e.target.submit();
            }
        });
    </script>
</body>
</html>