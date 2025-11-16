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
    header('Location: forgot_password.php?error=' . urlencode('Invalid request. Please try again.'));
    exit;
}

$action = $_POST['action'] ?? '';

// Handle password reset request
if ($action === 'request_reset') {
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    
    if (!$email) {
        header('Location: forgot_password.php?error=' . urlencode('Please enter a valid email address.'));
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
            header('Location: forgot_password.php?success=' . urlencode('If your email is registered, you will receive password reset instructions shortly.'));
            exit;
        }

        if ($user['status'] !== 'Active') {
            header('Location: forgot_password.php?error=' . urlencode('This account is not active. Please contact support.'));
            exit;
        }

        // Generate unique token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Store reset token in database
        $stmt = $pdo->prepare('INSERT INTO pcu_rfid2_password_resets (user_id, token, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$user['id'], $token, $expires]);

        // Send reset email
        $mail = new PHPMailer(true);
        
        try {
            $mail->SMTPDebug = 2;  // Enable verbose debug output
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

            $resetLink = 'http://' . $_SERVER['HTTP_HOST'] . '/pcuRFID2/reset_password_form.php?token=' . $token;
            
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
            
            header('Location: forgot_password.php?success=' . urlencode('If your email is registered, you will receive password reset instructions shortly.'));
            exit;

        } catch (Exception $e) {
            error_log('Mailer Error: ' . $mail->ErrorInfo);
            throw new Exception('Failed to send email: ' . $mail->ErrorInfo);
        }

    } catch (Exception $e) {
        error_log('Password reset error: ' . $e->getMessage());
        header('Location: forgot_password.php?error=' . urlencode('An error occurred. Please try again later.'));
        exit;
    }
} else if ($action === 'reset_password') {
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        header('Location: reset_password_form.php?token=' . urlencode($token) . '&error=' . urlencode('Password must be at least 8 characters long.'));
        exit;
    }

    if ($password !== $confirmPassword) {
        header('Location: reset_password_form.php?token=' . urlencode($token) . '&error=' . urlencode('Passwords do not match.'));
        exit;
    }

    try {
        $pdo = pdo();
        
        // Verify token and get user
        $stmt = $pdo->prepare('
            SELECT pr.user_id, u.email 
            FROM pcu_rfid2_password_resets pr 
            JOIN users u ON pr.user_id = u.id 
            WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW() 
            LIMIT 1
        ');
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if (!$reset) {
            header('Location: forgot_password.php?error=' . urlencode('Invalid or expired reset link.'));
            exit;
        }

        // Update password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([$hashedPassword, $reset['user_id']]);

        // Mark token as used
        $stmt = $pdo->prepare('UPDATE pcu_rfid2_password_resets SET used = 1 WHERE token = ?');
        $stmt->execute([$token]);

        header('Location: login.php?success=' . urlencode('Your password has been successfully reset. Please sign in with your new password.'));
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