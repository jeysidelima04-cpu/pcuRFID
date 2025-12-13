<?php
/**
 * Google OAuth Callback Handler
 * This file receives the response from Google after user signs in
 */

require_once 'vendor/autoload.php';
require_once 'config/google_config.php';
require_once 'db.php';

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get PDO instance
$pdo = pdo();

// Initialize Google Client
$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URI);
$client->addScope("email");
$client->addScope("profile");

try {
    // Validate OAuth state parameter (CSRF protection)
    if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || 
        $_GET['state'] !== $_SESSION['oauth_state']) {
        unset($_SESSION['oauth_state']);
        unset($_SESSION['oauth_state_time']);
        header('Location: login.php?error=' . urlencode('Invalid OAuth state. Please try again.'));
        exit;
    }
    
    // Check state expiration (10 minutes max)
    if (!isset($_SESSION['oauth_state_time']) || (time() - $_SESSION['oauth_state_time']) > 600) {
        unset($_SESSION['oauth_state']);
        unset($_SESSION['oauth_state_time']);
        header('Location: login.php?error=' . urlencode('OAuth session expired. Please try again.'));
        exit;
    }
    
    // Clear state after validation (single use)
    unset($_SESSION['oauth_state']);
    unset($_SESSION['oauth_state_time']);
    
    // Check if we have an authorization code
    if (!isset($_GET['code'])) {
        header('Location: login.php?error=no_code');
        exit;
    }

    // Exchange authorization code for access token
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    
    if (isset($token['error'])) {
        header('Location: login.php?error=token_error');
        exit;
    }

    $client->setAccessToken($token);

    // Get user profile information
    $google_oauth = new Google_Service_Oauth2($client);
    $google_account_info = $google_oauth->userinfo->get();
    
    $google_id = $google_account_info->id;
    $email = $google_account_info->email;
    $name = $google_account_info->name;
    $picture = $google_account_info->picture;
    $verified_email = $google_account_info->verifiedEmail;

    // Check if user already exists with this Google ID
    $stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
    $stmt->execute([$google_id]);
    $user = $stmt->fetch();

    if ($user) {
        // User exists with this Google ID - check verification status first
        
        // Check verification status
        if (isset($user['verification_status'])) {
            if ($user['verification_status'] === 'pending') {
                $_SESSION['info'] = 'Your account is pending for verification. Please wait for approval from the Student Services Office. You will receive an email once your account is verified.';
                header('Location: login.php');
                exit;
            }
            if ($user['verification_status'] === 'denied') {
                $_SESSION['error'] = 'Your account verification was denied. Please contact the Student Services Office for more information.';
                header('Location: login.php');
                exit;
            }
        }
        
        // Update last login
        $updateStmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $updateStmt->execute([$user['id']]);
        
        // Check if account is locked
        if ($user['status'] === 'Locked') {
            header('Location: login.php?error=account_locked');
            exit;
        }
        
        // Set session in correct format
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'student_id' => $user['student_id'],
            'login_method' => 'google'
        ];
        
        // Redirect based on role
        if ($user['role'] === 'Admin') {
            header('Location: admin/homepage.php');
        } else {
            header('Location: homepage.php');
        }
        exit;
        
    } else {
        // Check if email already exists (user signed up with email/password before)
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $existing_user = $stmt->fetch();
        
        if ($existing_user) {
            // Link Google account to existing user
            $linkStmt = $pdo->prepare('UPDATE users SET google_id = ?, status = "Active" WHERE id = ?');
            $linkStmt->execute([$google_id, $existing_user['id']]);
            
            // Update last login
            $updateStmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $updateStmt->execute([$existing_user['id']]);
            
            // Set session in correct format
            $_SESSION['user'] = [
                'id' => (int)$existing_user['id'],
                'name' => $existing_user['name'],
                'email' => $existing_user['email'],
                'role' => $existing_user['role'],
                'student_id' => $existing_user['student_id'],
                'login_method' => 'google'
            ];
            
            // Redirect based on role
            if ($existing_user['role'] === 'Admin') {
                header('Location: admin/homepage.php');
            } else {
                header('Location: homepage.php');
            }
            exit;
            
        } else {
            // New user - create account with temporary Student ID
            // Admin will update with real student ID later
            
            $temporaryStudentId = 'TEMP-' . time();
            
            // Create new user account
            // Password is set to a random hash since they'll use Google Sign-In
            $random_password = bin2hex(random_bytes(32));
            $hashed_password = password_hash($random_password, PASSWORD_DEFAULT);
            
            try {
                $insertStmt = $pdo->prepare('
                    INSERT INTO users (student_id, name, email, password, google_id, role, status, verification_status, created_at) 
                    VALUES (?, ?, ?, ?, ?, "Student", "Pending", "pending", NOW())
                ');
                
                $insertStmt->execute([
                    $temporaryStudentId,
                    $name,
                    $email,
                    $hashed_password,
                    $google_id
                ]);
                
                $new_user_id = $pdo->lastInsertId();
                
                // Send registration pending email
                $subject = 'PCU RFID System - Registration Submitted';
                $body = '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                        <div style="background-color: #0056b3; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="color: white; margin: 0; font-size: 28px;">PCU RFID System</h1>
                        </div>
                        <div style="background-color: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px;">
                            <h2 style="color: #0056b3; margin-top: 0;">Registration Received!</h2>
                            <p>Hello ' . htmlspecialchars($name) . ',</p>
                            <p>Thank you for registering with the PCU RFID System using Google Sign-In. Your account has been successfully created and is now awaiting verification by the Student Services Office.</p>
                            
                            <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                                <p style="margin: 0; color: #856404;"><strong>⏳ Verification Required</strong></p>
                                <p style="margin: 10px 0 0 0; color: #856404;">Your credentials will be verified by our administrators. You will receive an email notification once your account is approved.</p>
                            </div>
                            
                            <h3 style="color: #333; margin-top: 25px;">Account Details:</h3>
                            <ul style="color: #555; line-height: 1.8;">
                                <li><strong>Temporary ID:</strong> ' . htmlspecialchars($temporaryStudentId) . '</li>
                                <li><strong>Name:</strong> ' . htmlspecialchars($name) . '</li>
                                <li><strong>Email:</strong> ' . htmlspecialchars($email) . '</li>
                                <li><strong>Sign-In Method:</strong> Google Sign-In</li>
                            </ul>
                            
                            <div style="background-color: #d1ecf1; border-left: 4px solid #0c5460; padding: 15px; margin: 20px 0; border-radius: 4px;">
                                <p style="margin: 0; color: #0c5460;"><strong>ℹ️ Note About Student ID</strong></p>
                                <p style="margin: 10px 0 0 0; color: #0c5460;">You have been assigned a temporary student ID. The admin will update this with your official student ID during the verification process.</p>
                            </div>
                            
                            <p style="color: #6c757d; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6;">
                                <strong>Note:</strong> You will not be able to log in until your account has been verified. This typically takes 1-2 business days.
                            </p>
                        </div>
                    </div>';
                
                // Log email content (email sending can be configured later)
                error_log('New user registration email prepared for: ' . $email);
                error_log('Subject: ' . $subject);
                
                // Show pending verification message
                $_SESSION['info'] = 'Your account has been created successfully and is pending verification. You will receive an email once your account is approved by the Student Services Office.';
                header('Location: login.php');
                exit;
                
            } catch (PDOException $e) {
                error_log('New user registration error: ' . $e->getMessage());
                header('Location: login.php?error=registration_failed');
                exit;
            }
        }
    }
    
} catch (Exception $e) {
    // Log error and redirect
    error_log('Google OAuth Error: ' . $e->getMessage());
    header('Location: login.php?error=google_auth_failed');
    exit;
}
?>
