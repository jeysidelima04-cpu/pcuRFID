<?php
require_once __DIR__ . '/db.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'vendor/autoload.php';

// Enable error logging
ini_set('display_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['error'] = 'Invalid request. Please try again.';
    header('Location: forgot_password.php');
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'request_reset') {
    // Rate limiting: 3 reset requests per 15 minutes
    if (!check_rate_limit('password_reset', 3, 900)) {
        $_SESSION['error'] = 'Too many password reset attempts. Please try again in 15 minutes.';
        header('Location: forgot_password.php');
        exit;
    }
    
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    
    if (!$email) {
        $_SESSION['error'] = 'Please enter a valid email address.';
        header('Location: forgot_password.php');
        exit;
    }

    try {
        $pdo = pdo();
        
        // Check if email exists and get user info
        $stmt = $pdo->prepare('SELECT id, name, email, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            // Don't reveal if email exists or not
            $_SESSION['success'] = 'If your email is registered, you will receive password reset instructions shortly.';
            header('Location: forgot_password.php');
            exit;
        }

        if ($user['status'] !== 'Active') {
            $_SESSION['error'] = 'This account is not active. Please contact support.';
            header('Location: forgot_password.php');
            exit;
        }

        // Generate unique token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Store reset token in database
        $stmt = $pdo->prepare('INSERT INTO pcu_rfid2_password_resets (user_id, token, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$user['id'], $token, $expires]);

        $mail = new PHPMailer(true);

        try {
            $mail->SMTPDebug = 0; // Disable debug output in production
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'jeysidelima04@gmail.com';
            $mail->Password = 'donx oasl cjsw eywx';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;
            $mail->Timeout = 30;
            
            // Additional settings for better compatibility
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            $mail->setFrom('jeysidelima04@gmail.com', 'PCU RFID System');
            $mail->addAddress($user['email'], $user['name']);

            // Get the base URL dynamically (fix for Windows backslashes)
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $basePath = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
            $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . $basePath;
            $resetLink = $baseUrl . '/reset_password_form.php?token=' . $token;

            $mail->isHTML(true);
            $mail->Subject = 'Reset Your PCU RFID Password';
            $mail->Body = <<<HTML
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                <h2 style="color: #0369a1;">Password Reset Request</h2>
                <p>Hello {$user['name']},</p>
                <p>We received a request to reset your PCU RFID System password. If you didn't make this request, you can safely ignore this email.</p>
                <p>To reset your password, click the button below:</p>
                <p style="text-align: center;">
                    <a href="{$resetLink}" 
                       style="background-color: #0369a1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">
                        Reset Password
                    </a>
                </p>
                <p>This link will expire in 1 hour for security reasons.</p>
                <p>If the button doesn't work, copy and paste this link into your browser:</p>
                <p style="word-break: break-all; color: #666;">{$resetLink}</p>
                <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
                <p style="color: #666; font-size: 0.9em;">
                    This is an automated message, please do not reply to this email.
                </p>
            </div>
        HTML;

            $mail->send();
            error_log('Email sent successfully to ' . $user['email']);
            
            $_SESSION['success'] = 'If your email is registered, you will receive password reset instructions shortly.';
            header('Location: forgot_password.php');
            exit;

        } catch (Exception $e) {
            error_log('Mailer Error: ' . $mail->ErrorInfo);
            $_SESSION['error'] = 'Failed to send email. Please try again later.';
            header('Location: forgot_password.php');
            exit;
        }

    } catch (Exception $e) {
        error_log('Password reset error: ' . $e->getMessage());
        $_SESSION['error'] = 'An error occurred. Please try again later.';
        header('Location: forgot_password.php');
        exit;
    }
} elseif ($action === 'reset_password') {
    error_log('Starting password reset process');
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    error_log('Token received: ' . $token);

    if (empty($token) || empty($password) || empty($confirmPassword)) {
        header('Location: reset_password_form.php?token=' . urlencode($token) . '&error=' . urlencode('All fields are required.'));
        exit;
    }

    // Validate password strength
    if (strlen($password) < 8) {
        header('Location: reset_password_form.php?token=' . urlencode($token) . '&error=' . urlencode('Password must be at least 8 characters long.'));
        exit;
    }

    if (!preg_match('/[A-Z]/', $password)) {
        header('Location: reset_password_form.php?token=' . urlencode($token) . '&error=' . urlencode('Password must contain at least one uppercase letter.'));
        exit;
    }

    if (!preg_match('/[!@#$%^&*]/', $password)) {
        header('Location: reset_password_form.php?token=' . urlencode($token) . '&error=' . urlencode('Password must contain at least one special character (!@#$%^&*).'));
        exit;
    }

    if ($password !== $confirmPassword) {
        header('Location: reset_password_form.php?token=' . urlencode($token) . '&error=' . urlencode('Passwords do not match.'));
        exit;
    }

    try {
        $pdo = pdo();
        
        // First check if token exists and get all info
        $stmt = $pdo->prepare('
            SELECT pr.*, u.email, u.id as user_id
            FROM pcu_rfid2_password_resets pr 
            JOIN users u ON pr.user_id = u.id 
            WHERE pr.token = ?
            LIMIT 1
        ');
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if (!$reset) {
            error_log('Invalid reset attempt - Token: ' . $token . ' - Time: ' . date('Y-m-d H:i:s'));
            $_SESSION['error'] = 'Invalid or expired reset link. Please request a new one.';
            header('Location: forgot_password.php');
            exit;
        }

        // Update password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([$hashedPassword, $reset['user_id']]);

        // Mark token as used
        $stmt = $pdo->prepare('UPDATE pcu_rfid2_password_resets SET used = 1 WHERE token = ?');
        $stmt->execute([$token]);

        // Show success page with animation
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Password Reset Successful</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <script src="assets/js/tailwind.config.js"></script>
            <link rel="stylesheet" href="assets/css/styles.css">
            <style>
                @keyframes scaleCheckmark {
                    0% { transform: scale(0); }
                    50% { transform: scale(1.2); }
                    100% { transform: scale(1); }
                }
                @keyframes drawCheck {
                    0% { stroke-dashoffset: 100; }
                    100% { stroke-dashoffset: 0; }
                }
                .checkmark {
                    animation: scaleCheckmark 0.5s ease-in-out forwards;
                    transform-origin: center;
                }
                .checkmark__circle {
                    stroke-dasharray: 180;
                    stroke-dashoffset: 180;
                    animation: drawCheck 1s ease-out forwards;
                    animation-delay: 0.2s;
                }
                .modal-appear {
                    animation: modalAppear 0.3s ease-out forwards;
                }
                @keyframes modalAppear {
                    from {
                        opacity: 0;
                        transform: translateY(-20px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
            </style>
        </head>
        <body class="bg-pcu min-h-screen">
            <div class="fixed inset-0 flex items-center justify-center p-4">
                <div class="modal-appear w-full max-w-sm bg-white/95 rounded-2xl shadow-2xl p-8 text-center">
                    <div class="mb-6">
                        <svg class="checkmark mx-auto w-24 h-24" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
                            <circle class="checkmark__circle" cx="26" cy="26" r="25" fill="none" stroke="#0EA5E9" stroke-width="2"/>
                            <path class="checkmark__circle" fill="none" stroke="#0EA5E9" stroke-width="2" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-semibold text-sky-600 mb-4">Password Reset Successful!</h2>
                    <p class="text-slate-600 mb-8">Your password has been successfully updated. You'll be redirected to the login page in a moment.</p>
                    <div class="animate-pulse">
                        <div class="h-1 w-full bg-sky-200 rounded">
                            <div class="h-1 bg-sky-600 rounded transition-all duration-3000 w-progress"></div>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                setTimeout(() => {
                    // Store success message in session
                    fetch('auth.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ action: 'set_toast', message: 'Your password has been successfully reset. Please sign in with your new password.' })
                    }).then(() => {
                        window.location.href = 'login.php';
                    });
                }, 3000);
            </script>
        </body>
        </html>
        <?php
        exit;

    } catch (Exception $e) {
        error_log('Password reset error: ' . $e->getMessage());
        header('Location: reset_password_form.php?token=' . urlencode($token) . '&error=' . urlencode('An error occurred. Please try again later.'));
        exit;
    }
} else {
    header('Location: login.php');
    exit;
}