<?php
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF protection for JSON API (check custom header)
$headers = getallheaders();
if (!isset($headers['X-CSRF-Token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $headers['X-CSRF-Token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$userId = $_SESSION['user']['id'];

try {
    $pdo = pdo();
    
    // Get user's current profile picture
    $stmt = $pdo->prepare('SELECT profile_picture FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $profilePicture = $stmt->fetchColumn();
    
    if (!$profilePicture) {
        echo json_encode(['success' => false, 'error' => 'No profile picture to delete']);
        exit;
    }
    
    // Delete file
    $filepath = __DIR__ . '/assets/images/profiles/' . $profilePicture;
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    
    // Update database
    $stmt = $pdo->prepare('UPDATE users SET profile_picture = NULL, profile_picture_uploaded_at = NULL WHERE id = ?');
    $stmt->execute([$userId]);
    
    // Update session
    $_SESSION['user']['profile_picture'] = null;
    
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture deleted successfully'
    ]);
    
} catch (Exception $e) {
    error_log('Profile picture delete error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to delete profile picture']);
}
