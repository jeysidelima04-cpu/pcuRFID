<?php
require_once 'db.php';
verify_csrf();

$email = $_GET['email'] ?? '';
$info = $_GET['info'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify 2FA Code - PCU RFID System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        .code-input {
            letter-spacing: 0.5em;
            text-align: center;
            font-size: 1.5em;
        }
    </style>
</head>
<body class="h-full bg-cover bg-center" style="background-image: url('pcu-building.jpg');">
    <div class="min-h-full flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full bg-white/90 backdrop-blur-sm p-8 rounded-lg shadow-lg space-y-8">
            <!-- Logo -->
            <div>
                <img class="mx-auto h-20 w-auto" src="pcu-logo.png" alt="PCU Logo">
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    Two-Factor Authentication
                </h2>
                <p class="mt-2 text-center text-sm text-gray-600">
                    Enter the verification code sent to<br>
                    <span class="font-medium text-blue-600"><?= htmlspecialchars($email) ?></span>
                </p>
            </div>

            <!-- Error/Info Messages -->
            <?php if ($error): ?>
            <div class="bg-red-50 border-l-4 border-red-400 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-red-700"><?= htmlspecialchars($error) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($info): ?>
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-blue-700"><?= htmlspecialchars($info) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 2FA Form -->
            <form class="mt-8 space-y-6" action="auth.php" method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="verify_2fa">
                <div class="rounded-md -space-y-px">
                    <div>
                        <label for="code" class="sr-only">Verification Code</label>
                        <input id="code" name="code" type="text" required
                            class="code-input appearance-none rounded relative block w-full px-3 py-4 border border-gray-300 placeholder-gray-500 text-gray-900 focus:outline-none focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm"
                            placeholder="Enter 6-digit code"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            inputmode="numeric"
                            autocomplete="one-time-code">
                    </div>
                </div>

                <div>
                    <button type="submit"
                        class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Verify Code
                    </button>
                </div>
            </form>

            <!-- Resend Form -->
            <div class="text-center">
                <form id="resendForm" action="auth.php" method="POST" class="inline">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="resend_2fa">
                    <button type="submit" id="resendButton" 
                        class="text-sm text-blue-600 hover:text-blue-500 disabled:text-gray-400 disabled:cursor-not-allowed">
                        Resend code
                    </button>
                </form>
                <span id="timer" class="text-sm text-gray-500 ml-2"></span>
            </div>
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
    </script>
</body>
</html>
