<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Check if request is POST and has valid JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit;
}

// Validate required fields
if (empty($input['student_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student ID']);
    exit;
}

try {
    $pdo = pdo();
    
    // Unregister RFID card
    $stmt = $pdo->prepare('
        UPDATE users 
        SET rfid_uid = NULL, rfid_registered_at = NULL 
        WHERE id = ? AND role = "Student"
    ');
    
    if ($stmt->execute([$input['student_id']])) {
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Student not found']);
        }
    } else {
        throw new Exception('Failed to update database');
    }
    
} catch (Exception $e) {
    error_log('Error unregistering RFID card: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to unregister RFID card']);
}