<?php
/**
 * Upload Student Profile Picture (Admin Only)
 * Allows admins to upload profile pictures for students
 */

require_once __DIR__ . '/../db.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF protection for file upload (token sent in POST data)
error_log('POST csrf_token: ' . ($_POST['csrf_token'] ?? 'NOT SET'));
error_log('SESSION csrf_token: ' . ($_SESSION['csrf_token'] ?? 'NOT SET'));
error_log('POST data: ' . print_r($_POST, true));

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid CSRF token',
        'debug' => [
            'post_has_token' => isset($_POST['csrf_token']),
            'session_has_token' => isset($_SESSION['csrf_token']),
            'post_token_preview' => isset($_POST['csrf_token']) ? substr($_POST['csrf_token'], 0, 10) : 'none',
            'session_token_preview' => isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 10) : 'none'
        ]
    ]);
    exit;
}

// Validate student_id parameter
$studentId = $_POST['student_id'] ?? null;

if (!$studentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student ID']);
    exit;
}

// Validate file upload
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['file'];

// Validate file type
$allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime_type, $allowed_types)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPG and PNG are allowed']);
    exit;
}

// Validate file size (5MB max)
$max_size = 5 * 1024 * 1024; // 5MB in bytes
if ($file['size'] > $max_size) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum size is 5MB']);
    exit;
}

try {
    $pdo = pdo();
    
    // Verify student exists
    $stmt = $pdo->prepare('SELECT id, profile_picture FROM users WHERE id = ? AND role = "Student"');
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    
    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit;
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'student_' . $studentId . '_' . time() . '.' . $extension;
    $upload_dir = __DIR__ . '/../assets/images/profiles/';
    $upload_path = $upload_dir . $filename;
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Delete old profile picture if exists
    if ($student['profile_picture']) {
        $old_file = $upload_dir . $student['profile_picture'];
        if (file_exists($old_file)) {
            unlink($old_file);
        }
    }
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
        exit;
    }
    
    // Update database
    $updateStmt = $pdo->prepare('UPDATE users SET profile_picture = ? WHERE id = ?');
    $updateStmt->execute([$filename, $studentId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture uploaded successfully',
        'filename' => $filename
    ]);
    
} catch (PDOException $e) {
    error_log('Upload student picture error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log('Upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An error occurred while uploading']);
}
?>
