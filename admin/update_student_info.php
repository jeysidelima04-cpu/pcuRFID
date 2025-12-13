<?php
/**
 * Update Student Information (Admin Only)
 * Allows admins to update student name and student ID
 */

require_once __DIR__ . '/../db.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Verify CSRF token
if (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || $_SERVER['HTTP_X_CSRF_TOKEN'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Validate input
$userId = $data['user_id'] ?? null;
$name = trim($data['name'] ?? '');
$studentId = trim($data['student_id'] ?? '');

if (!$userId || !$name || !$studentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

if (strlen($studentId) < 3) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Student ID must be at least 3 characters']);
    exit;
}

try {
    $pdo = pdo();
    
    // Check if student_id already exists for a different user
    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE student_id = ? AND id != ?');
    $checkStmt->execute([$studentId, $userId]);
    
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This Student ID is already in use by another student']);
        exit;
    }
    
    // Update student information
    $updateStmt = $pdo->prepare('
        UPDATE users 
        SET name = ?, student_id = ? 
        WHERE id = ? AND role = "Student"
    ');
    
    $updateStmt->execute([$name, $studentId, $userId]);
    
    if ($updateStmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Student information updated successfully'
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student not found or no changes made']);
    }
    
} catch (PDOException $e) {
    error_log('Update student info error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
?>
