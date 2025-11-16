<?php
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

// Verify CSRF token
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Invalid CSRF token']));
}

if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit(json_encode([
        'success' => false,
        'message' => 'No file uploaded or upload error occurred'
    ]));
}

$file = $_FILES['profile_picture'];
$userId = $_SESSION['user']['id'];

// Validate file type
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
$fileInfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($fileInfo, $file['tmp_name']);
finfo_close($fileInfo);

if (!in_array($mimeType, $allowedTypes)) {
    http_response_code(400);
    exit(json_encode([
        'success' => false,
        'message' => 'Invalid file type. Only JPG, PNG, and GIF files are allowed.'
    ]));
}

// Validate file size (max 5MB)
$maxSize = 5 * 1024 * 1024; // 5MB in bytes
if ($file['size'] > $maxSize) {
    http_response_code(400);
    exit(json_encode([
        'success' => false,
        'message' => 'File is too large. Maximum size is 5MB.'
    ]));
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$newFilename = $userId . '_' . uniqid() . '.' . $extension;
$uploadPath = __DIR__ . '/assets/images/profiles/' . $newFilename;

// Create directory if it doesn't exist
if (!is_dir(__DIR__ . '/assets/images/profiles')) {
    mkdir(__DIR__ . '/assets/images/profiles', 0755, true);
}

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
    http_response_code(500);
    exit(json_encode([
        'success' => false,
        'message' => 'Failed to save the uploaded file.'
    ]));
}

try {
    $pdo = pdo();
    
    // Get old profile picture if exists
    $stmt = $pdo->prepare('SELECT profile_picture FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $oldPicture = $stmt->fetchColumn();

    // Update database with new profile picture
    $stmt = $pdo->prepare('UPDATE users SET profile_picture = ? WHERE id = ?');
    $stmt->execute(['/pcuRFID2/assets/images/profiles/' . $newFilename, $userId]);

    // Delete old profile picture if exists
    if ($oldPicture && $oldPicture !== '/pcuRFID2/assets/images/default-profile.png') {
        $oldPath = __DIR__ . str_replace('/pcuRFID2', '', $oldPicture);
        if (file_exists($oldPath)) {
            unlink($oldPath);
        }
    }

    exit(json_encode([
        'success' => true,
        'message' => 'Profile picture updated successfully',
        'profile_picture_url' => 'assets/images/profiles/' . $newFilename
    ]));

} catch (Exception $e) {
    error_log('Profile picture update error: ' . $e->getMessage());
    // Delete uploaded file if database update fails
    if (file_exists($uploadPath)) {
        unlink($uploadPath);
    }
    http_response_code(500);
    exit(json_encode([
        'success' => false,
        'message' => 'Failed to update profile picture in database.'
    ]));
}