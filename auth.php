<?php
// auth.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-XSS-Protection: 1; mode=block');

$action = $_POST['action'] ?? '';
$redirectBack = function(string $page, array $params = []) {
    $q = http_build_query($params);
    header("Location: {$page}" . ($q ? "?{$q}" : ''));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

verify_csrf();

try {
    switch ($action) {
        case 'signup':
            handleSignup();
            break;
        case 'login':
            handleLogin();
            break;
        case 'verify_2fa':
            handleVerify2FA();
            break;
        case 'resend_2fa':
            handleResend2FA();
            break;
        case 'logout':
            session_destroy();
            session_start(); // Restart session to store the message
            $_SESSION['toast'] = 'Logged out';
            header('Location: login.php');
            exit;
            break;
        case 'set_toast':
            // Simple action to set toast message in session (called via AJAX)
            $_SESSION['toast'] = $_POST['message'] ?? '';
            echo json_encode(['success' => true]);
            exit;
        default:
            http_response_code(400);
            exit('Unknown action');
    }
} catch (Throwable $e) {
    error_log("[PCU RFID Error] " . $e->getMessage());
    error_log("[PCU RFID Error] Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    // Display error in development
    if (true) {  // Change this to false in production
        echo "<h1>Server Error</h1>";
        echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        exit;
    }
    exit('Server error');
}

function handleSignup(): void {
    error_log("[PCU RFID] Starting signup process");
    
    $pdo = pdo();
    error_log("[PCU RFID] Database connection successful");

    // Get and validate form data
    $student_id = trim($_POST['student_id'] ?? '');
    $name       = trim($_POST['name'] ?? '');
    $email      = strtolower(trim($_POST['email'] ?? ''));
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $role       = 'Student'; // Set default role to Student

    error_log("[PCU RFID] Form data received: " . json_encode([
        'student_id' => $student_id,
        'name' => $name,
        'email' => $email,
        'has_password' => !empty($password),
        'has_confirm' => !empty($confirm),
        'role' => $role
    ]));

    if (!$student_id || !$name || !$email || !$password || !$confirm) {
        redirect_error('signup.php', 'Please fill in all fields.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirect_error('signup.php', 'Invalid email.');
    }
    if ($password !== $confirm) {
        redirect_error('signup.php', 'Passwords do not match.');
    }
    if (strlen($password) < 8) {
        redirect_error('signup.php', 'Password must be at least 8 characters.');
    }

    // Always set role as Student and level as College for new signups
    $role = 'Student';
    $_POST['student_type'] = 'college'; // Ensure college type is set

    // Check duplicates
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email OR student_id = :sid LIMIT 1');
    $stmt->execute([':email' => $email, ':sid' => $student_id]);
    if ($stmt->fetch()) {
        redirect_error('signup.php', 'Email or Student ID already in use.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    // Create user with Pending status
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO users (student_id, name, email, password, role, status) VALUES (:sid, :name, :email, :pass, :role, "Pending")');
        $stmt->execute([
            ':sid' => $student_id,
            ':name' => $name,
            ':email' => $email,
            ':pass' => $hash,
            ':role' => $role,
        ]);
        $userId = (int)$pdo->lastInsertId();

        // Generate 6-digit code and store with 5-minute expiry
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('INSERT INTO twofactor_codes (user_id, code, expires_at) VALUES (:uid, :code, DATE_ADD(NOW(), INTERVAL 5 MINUTE))');
        $stmt->execute([':uid' => $userId, ':code' => $code]);

        $pdo->commit();

        // Email the code
        $subject = 'Welcome to PCU RFID System - Verify Your Account';
        $body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                <img src="https://pcu.edu.ph/wp-content/uploads/2022/12/pcu-logo.png" alt="PCU Logo" style="display: block; margin: 0 auto; width: 150px;">
                <h2 style="color: #1e40af; text-align: center; margin-top: 20px;">Welcome to PCU RFID System</h2>
                <p>Hello ' . htmlspecialchars($name) . ',</p>
                <p>Thank you for registering with the PCU RFID System. To complete your registration and ensure the security of your account, please use the verification code below:</p>
                <div style="background-color: #f3f4f6; padding: 20px; border-radius: 8px; text-align: center; margin: 20px 0;">
                    <span style="font-size: 32px; letter-spacing: 4px; font-family: monospace; color: #1e40af;">' . $code . '</span>
                </div>
                <p><strong>Important:</strong></p>
                <ul style="color: #4b5563;">
                    <li>This code will expire in 5 minutes</li>
                    <li>If you did not request this code, please ignore this email</li>
                    <li>Never share this code with anyone</li>
                </ul>
                <p style="color: #6b7280; font-size: 14px; text-align: center; margin-top: 30px;">
                    This is an automated message from the PCU RFID System.<br>
                    Please do not reply to this email.
                </p>
            </div>';
        sendMail($email, $subject, $body);

        $_SESSION['pending_user_id'] = $userId;
        $_SESSION['verify_email'] = $email;
        $_SESSION['info'] = 'Code sent';
        header('Location: verify_2fa.php');
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handleLogin(): void {
    $pdo = pdo();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        redirect_error('login.php', 'Please enter email and password.');
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        sleep(1);
        redirect_error('login.php', 'Invalid credentials.');
    }

    if ($user['status'] === 'Locked') {
        redirect_error('login.php', 'Account locked due to too many failed attempts.');
    }
    if (!password_verify($password, $user['password'])) {
        $failed = (int)$user['failed_attempts'] + 1;
        $status = $failed >= 5 ? 'Locked' : $user['status'];
        $stmt = $pdo->prepare('UPDATE users SET failed_attempts = :f, status = :s WHERE id = :id');
        $stmt->execute([':f' => $failed, ':s' => $status, ':id' => $user['id']]);
        if ($status === 'Locked') {
            redirect_error('login.php', 'Too many failed attempts. Account locked.');
        }
        redirect_error('login.php', 'Invalid credentials.');
    }

    if ($user['status'] === 'Pending') {
        // Resend a fresh 2FA code for convenience
        generateAndSend2FA((int)$user['id'], $user['name'], $user['email']);
        $_SESSION['pending_user_id'] = (int)$user['id'];
        $_SESSION['verify_email'] = $user['email'];
        $_SESSION['info'] = 'Verify your account';
        header('Location: verify_2fa.php');
        exit;
    }

    // Success: reset attempts, update last_login, set session
    $stmt = $pdo->prepare('UPDATE users SET failed_attempts = 0, last_login = NOW() WHERE id = :id');
    $stmt->execute([':id' => $user['id']]);

    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];

    // Redirect based on role
    if ($user['role'] === 'Student') {
        header('Location: homepage.php');
        exit;
    } elseif ($user['role'] === 'Admin') {
        header('Location: admin.php');
        exit;
    }
    
    // If role is not recognized
    redirect_error('login.php', 'Invalid user role. This system is for students only.');
    exit;
}

function handleVerify2FA(): void {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    $pdo = pdo();
    $code = trim($_POST['code'] ?? '');
    $userId = (int)($_SESSION['pending_user_id'] ?? 0);

    if (!$userId || !preg_match('/^\d{6}$/', $code)) {
        if ($isAjax) {
            http_response_code(400);
            exit('Invalid code.');
        }
        redirect_error('verify_2fa.php', 'Invalid code.');
    }

    $stmt = $pdo->prepare('SELECT t.id, t.expires_at, u.status FROM twofactor_codes t INNER JOIN users u ON u.id = t.user_id WHERE t.user_id = :uid AND t.code = :code ORDER BY t.id DESC LIMIT 1');
    $stmt->execute([':uid' => $userId, ':code' => $code]);
    $row = $stmt->fetch();

    if (!$row) {
        redirect_error('verify_2fa.php', 'Incorrect code.');
    }
    if (strtotime($row['expires_at']) < time()) {
        redirect_error('verify_2fa.php', 'Code expired. Please resend a new code.');
    }

    // Activate account
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE users SET status = "Active", failed_attempts = 0 WHERE id = :id');
        $stmt->execute([':id' => $userId]);

        // Remove used/old codes
        $stmt = $pdo->prepare('DELETE FROM twofactor_codes WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    unset($_SESSION['pending_user_id']);
    
    if ($isAjax) {
        exit('Account verified');
    }
    
    $_SESSION['toast'] = 'Account verified. You can now log in.';
    header('Location: login.php');
    exit;
}

function handleResend2FA(): void {
    $pdo = pdo();
    $userId = (int)($_SESSION['pending_user_id'] ?? 0);
    if (!$userId) redirect_error('login.php', 'No pending verification found.');

    $stmt = $pdo->prepare('SELECT id, name, email, status FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) redirect_error('login.php', 'User not found.');
    if ($user['status'] !== 'Pending') redirect_error('login.php', 'Account already verified.');

    generateAndSend2FA((int)$user['id'], $user['name'], $user['email']);

    $_SESSION['verify_email'] = $user['email'];
    $_SESSION['info'] = 'New code sent';
    header('Location: verify_2fa.php');
    exit;
}

function generateAndSend2FA(int $userId, string $name, string $email): void {
    $pdo = pdo();
    // Remove any existing codes
    $stmt = $pdo->prepare('DELETE FROM twofactor_codes WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare('INSERT INTO twofactor_codes (user_id, code, expires_at) VALUES (:uid, :code, DATE_ADD(NOW(), INTERVAL 5 MINUTE))');
    $stmt->execute([':uid' => $userId, ':code' => $code]);

    $subject = 'Your PCU RFID 2FA Code';
    $body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
            <img src="https://pcu.edu.ph/wp-content/uploads/2022/12/pcu-logo.png" alt="PCU Logo" style="display: block; margin: 0 auto; width: 150px;">
            <h2 style="color: #1e40af; text-align: center; margin-top: 20px;">PCU RFID System Verification</h2>
            <p>Hello ' . htmlspecialchars($name) . ',</p>
            <p>Here is your verification code:</p>
            <div style="background-color: #f3f4f6; padding: 20px; border-radius: 8px; text-align: center; margin: 20px 0;">
                <span style="font-size: 32px; letter-spacing: 4px; font-family: monospace; color: #1e40af;">' . $code . '</span>
            </div>
            <p><strong>Important:</strong></p>
            <ul style="color: #4b5563;">
                <li>This code will expire in 5 minutes</li>
                <li>If you did not request this code, please ignore this email</li>
                <li>Never share this code with anyone</li>
            </ul>
            <p style="color: #6b7280; font-size: 14px; text-align: center; margin-top: 30px;">
                This is an automated message from the PCU RFID System.<br>
                Please do not reply to this email.
            </p>
        </div>';
    sendMail($email, $subject, $body);
}

function redirect_error(string $page, string $msg): void {
    header('Location: ' . $page . '?error=' . urlencode($msg));
    exit;
}
