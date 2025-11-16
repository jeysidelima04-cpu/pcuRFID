<?php
require_once __DIR__ . '/../db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check if request is POST and has JSON content
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty(file_get_contents('php://input'))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['student_id'])) {
        throw new Exception('Missing student ID');
    }

    $pdo = pdo();
    
    // Delete user record
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "Student"');
    $success = $stmt->execute([$data['student_id']]);

    if (!$success) {
        throw new Exception('Failed to delete user record');
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('Delete account error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}