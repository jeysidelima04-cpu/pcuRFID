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
    }
    exit('Server error');
}

function handleSignup(): void {
    error_log("[PCU RFID] Starting signup process");
    
    $pdo = pdo();
    error_log("[PCU RFID] Database connection successful");

    // Get and validate form data
    $student_id = trim($_POST['student_id'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $surname    = trim($_POST['surname'] ?? '');
    
    // Combine name fields into full name
    $name_parts = array_filter([$first_name, $middle_name, $surname]);
    $name = implode(' ', $name_parts);
    
    $email      = strtolower(trim($_POST['email'] ?? ''));
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $role       = 'Student'; // Set default role to Student

    error_log("[PCU RFID] Form data received: " . json_encode([
        'student_id' => $student_id,
        'first_name' => $first_name,
        'middle_name' => $middle_name,
        'surname' => $surname,
        'full_name' => $name,
        'email' => $email,
        'has_password' => !empty($password),
        'has_confirm' => !empty($confirm),
        'role' => $role
    ]));

    if (!$student_id || !$first_name || !$surname || !$email || !$password || !$confirm) {
        redirect_error('signup.php', 'Please fill in all required fields.');
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

    // Create user with Pending status and pending verification
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO users (student_id, name, email, password, role, status, verification_status) VALUES (:sid, :name, :email, :pass, :role, "Pending", "pending")');
        $stmt->execute([
            ':sid' => $student_id,
            ':name' => $name,
            ':email' => $email,
            ':pass' => $hash,
            ':role' => $role,
        ]);
        $userId = (int)$pdo->lastInsertId();

        $pdo->commit();

        // Send registration submitted email
        $subject = 'PCU RFID System - Registration Submitted';
        $body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                <div style="background-color: #0056b3; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                    <h1 style="color: white; margin: 0; font-size: 28px;">PCU RFID System</h1>
                </div>
                <div style="background-color: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px;">
                    <h2 style="color: #0056b3; margin-top: 0;">Registration Received!</h2>
                    <p>Hello ' . htmlspecialchars($name) . ',</p>
                    <p>Thank you for registering with the PCU RFID System. Your account has been successfully created and is now awaiting verification by the Student Services Office.</p>
                    
                    <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin: 0; color: #856404;"><strong>⏳ Verification Required</strong></p>
                        <p style="margin: 10px 0 0 0; color: #856404;">Your credentials will be verified by our administrators. You will receive an email notification once your account is approved.</p>
                    </div>
                    
                    <h3 style="color: #333; margin-top: 25px;">Account Details:</h3>
                    <ul style="color: #555; line-height: 1.8;">
                        <li><strong>Student ID:</strong> ' . htmlspecialchars($student_id) . '</li>
                        <li><strong>Name:</strong> ' . htmlspecialchars($name) . '</li>
                        <li><strong>Email:</strong> ' . htmlspecialchars($email) . '</li>
                    </ul>
                    
                    <p style="color: #6c757d; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6;">
                        <strong>Note:</strong> You will not be able to log in until your account has been verified. This typically takes 1-2 business days.
                    </p>
                    
                    <p style="color: #6c757d; font-size: 12px; text-align: center; margin-top: 30px;">
                        This is an automated message from the PCU RFID System.<br>
                        If you did not create this account, please contact the Student Services Office immediately.
                    </p>
                </div>
            </div>';
        sendMail($email, $subject, $body);

        // Redirect to login page with info message
        $_SESSION['info'] = 'Registration submitted successfully! Your account is pending for verification by the Student Services Office. You will receive an email after the verification.';
        header('Location: login.php');
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

    // Check verification status
    if (isset($user['verification_status'])) {
        if ($user['verification_status'] === 'pending') {
            $_SESSION['info'] = 'Your account is pending verification. Please wait for approval from the Student Services Office.';
            header('Location: login.php');
            exit;
        }
        if ($user['verification_status'] === 'denied') {
            $_SESSION['error'] = 'Your account verification was denied. Please contact the Student Services Office for more information.';
            header('Location: login.php');
            exit;
        }
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
