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
    
    if (!isset($data['student_id']) || !isset($data['rfid_uid'])) {
        throw new Exception('Missing required fields');
    }

    // Sanitize UID - remove spaces, keep as-is (R20XC outputs decimal numbers)
    $rfid_uid = trim($data['rfid_uid']);
    
    // Log what we received for debugging
    error_log('RFID Scanner Output: [' . $rfid_uid . '] Length: ' . strlen($rfid_uid));
    
    // Validate format - R20XC-USB outputs 10-digit decimal numbers
    if (strlen($rfid_uid) < 4 || strlen($rfid_uid) > 20) {
        throw new Exception('Invalid RFID length. Received: ' . strlen($rfid_uid) . ' characters. Expected: 4-20 characters.');
    }
    
    // Allow alphanumeric characters (covers both decimal and hex formats)
    if (!preg_match('/^[0-9A-Fa-f]+$/', $rfid_uid)) {
        throw new Exception('Invalid RFID format. Received: "' . $rfid_uid . '". Only numbers and letters (0-9, A-F) are allowed.');
    }

    $pdo = pdo();
    
    // Check if RFID is already registered to another student
    $stmt = $pdo->prepare('SELECT id, name FROM users WHERE rfid_uid = ? AND id != ?');
    $stmt->execute([$rfid_uid, $data['student_id']]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        throw new Exception('This card is already registered to ' . $existing['name']);
    }

    // Update user record with RFID UID, set registration timestamp, and status to Active
    $stmt = $pdo->prepare('UPDATE users SET rfid_uid = ?, rfid_registered_at = NOW(), status = "Active" WHERE id = ?');
    $success = $stmt->execute([$rfid_uid, $data['student_id']]);

    if (!$success || $stmt->rowCount() === 0) {
        throw new Exception('Student not found or failed to update record');
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('RFID registration error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}