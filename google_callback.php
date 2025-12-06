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
            // New user - need to complete registration with Student ID
            // Store Google account info in session temporarily
            $_SESSION['google_registration'] = [
                'google_id' => $google_id,
                'email' => $email,
                'name' => $name,
                'picture' => $picture,
                'verified_email' => $verified_email
            ];
            
            // Redirect to complete registration page
            header('Location: complete_google_registration.php');
            exit;
        }
    }
    
} catch (Exception $e) {
    // Log error and redirect
    error_log('Google OAuth Error: ' . $e->getMessage());
    header('Location: login.php?error=google_auth_failed');
    exit;
}
?>
