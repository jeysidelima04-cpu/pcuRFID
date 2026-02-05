<?php
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF protection for file upload (token sent in POST data)
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$userId = $_SESSION['user']['id'];
$file = $_FILES['file'];

// Validate file type (only images)
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$fileType = mime_content_type($file['tmp_name']);

if (!in_array($fileType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.']);
    exit;
}

// Validate file size (max 5MB)
$maxSize = 5 * 1024 * 1024; // 5MB in bytes
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum size is 5MB.']);
    exit;
}

try {
    $pdo = pdo();
    
    // Get user's current profile picture to delete old file
    $stmt = $pdo->prepare('SELECT profile_picture FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $oldPicture = $stmt->fetchColumn();
    
    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/assets/images/profiles/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'profile_' . $userId . '_' . time() . '.' . $extension;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to move uploaded file');
    }
    
    // Optimize/resize image using GD library
    $optimizedPath = optimizeImage($filepath, $extension);
    if ($optimizedPath) {
        // Delete original if optimization succeeded
        if ($optimizedPath !== $filepath) {
            unlink($filepath);
            $filepath = $optimizedPath;
            $filename = basename($optimizedPath);
        }
    }
    
    // Update database
    $stmt = $pdo->prepare('UPDATE users SET profile_picture = ?, profile_picture_uploaded_at = NOW() WHERE id = ?');
    $stmt->execute([$filename, $userId]);
    
    // Delete old profile picture file if exists
    if ($oldPicture && file_exists($uploadDir . $oldPicture)) {
        unlink($uploadDir . $oldPicture);
    }
    
    // Update session
    $_SESSION['user']['profile_picture'] = $filename;
    
    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'url' => 'assets/images/profiles/' . $filename,
        'message' => 'Profile picture uploaded successfully'
    ]);
    
} catch (Exception $e) {
    error_log('Profile picture upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to upload profile picture']);
}

/**
 * Optimize and resize image
 */
function optimizeImage($filepath, $extension) {
    $maxWidth = 500;
    $maxHeight = 500;
    $quality = 85;
    
    // Get image dimensions
    list($width, $height) = getimagesize($filepath);
    
    // Calculate new dimensions (maintain aspect ratio)
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = round($width * $ratio);
    $newHeight = round($height * $ratio);
    
    // Create image resource based on type
    switch (strtolower($extension)) {
        case 'jpg':
        case 'jpeg':
            $source = imagecreatefromjpeg($filepath);
            break;
        case 'png':
            $source = imagecreatefrompng($filepath);
            break;
        case 'gif':
            $source = imagecreatefromgif($filepath);
            break;
        case 'webp':
            $source = imagecreatefromwebp($filepath);
            break;
        default:
            return false;
    }
    
    if (!$source) {
        return false;
    }
    
    // Create new image
    $newImage = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG and GIF
    if (in_array(strtolower($extension), ['png', 'gif'])) {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    // Resize
    imagecopyresampled($newImage, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Save optimized image
    $optimizedPath = $filepath;
    switch (strtolower($extension)) {
        case 'jpg':
        case 'jpeg':
            imagejpeg($newImage, $optimizedPath, $quality);
            break;
        case 'png':
            imagepng($newImage, $optimizedPath, round((100 - $quality) / 10));
            break;
        case 'gif':
            imagegif($newImage, $optimizedPath);
            break;
        case 'webp':
            imagewebp($newImage, $optimizedPath, $quality);
            break;
    }
    
    // Free memory
    imagedestroy($source);
    imagedestroy($newImage);
    
    return $optimizedPath;
}
