<?php

require_once __DIR__ . '/../db.php';

// Check if super admin is logged in
if (isset($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true) {
    try {
        $pdo = pdo();
        
        // Log the logout action
        $stmt = $pdo->prepare("INSERT INTO superadmin_audit_log (super_admin_id, action, ip_address, user_agent) VALUES (?, 'LOGOUT', ?, ?)");
        $stmt->execute([
            $_SESSION['superadmin_id'],
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    } catch (PDOException $e) {
        error_log("Logout audit log error: " . $e->getMessage());
    }
}

// Fully destroy the session (not just selective unset)
destroy_session_completely();

// Redirect to login page with no-cache
send_no_cache_headers();
header('Location: superadmin_login.php');
exit;
