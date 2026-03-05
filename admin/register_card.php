<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF protection for JSON API
$headers = getallheaders();
if (!isset($headers['X-CSRF-Token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $headers['X-CSRF-Token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
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
        throw new \Exception('Missing required fields');
    }

    // Sanitize UID - remove spaces, keep as-is (R20XC outputs decimal numbers)
    $rfid_uid = trim($data['rfid_uid']);
    
    // Log what we received for debugging
    error_log('RFID Scanner Output: [' . $rfid_uid . '] Length: ' . strlen($rfid_uid));
    
    // Validate format - R20XC-USB outputs 10-digit decimal numbers
    if (strlen($rfid_uid) < 4 || strlen($rfid_uid) > 20) {
        throw new \Exception('Invalid RFID length. Received: ' . strlen($rfid_uid) . ' characters. Expected: 4-20 characters.');
    }
    
    // Allow alphanumeric characters (covers both decimal and hex formats)
    if (!preg_match('/^[0-9A-Fa-f]+$/', $rfid_uid)) {
        throw new \Exception('Invalid RFID format. Received: "' . $rfid_uid . '". Only numbers and letters (0-9, A-F) are allowed.');
    }

    $pdo = pdo();
    
    // Check if RFID is already registered to another student
    $stmt = $pdo->prepare('SELECT id, name FROM users WHERE rfid_uid = ? AND id != ?');
    $stmt->execute([$rfid_uid, $data['student_id']]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        throw new \Exception('This card is already registered to ' . $existing['name']);
    }

    // Update user record with RFID UID, set registration timestamp, and status to Active
    $stmt = $pdo->prepare('UPDATE users SET rfid_uid = ?, rfid_registered_at = NOW(), status = "Active" WHERE id = ?');
    $success = $stmt->execute([$rfid_uid, $data['student_id']]);

    if (!$success || $stmt->rowCount() === 0) {
        throw new \Exception('Student not found or failed to update record');
    }
    
    // Fetch student name for audit logging
    $nameStmt = $pdo->prepare('SELECT name, student_id FROM users WHERE id = ?');
    $nameStmt->execute([$data['student_id']]);
    $studentInfo = $nameStmt->fetch(\PDO::FETCH_ASSOC);
    $studentName = $studentInfo['name'] ?? 'Unknown';
    $studentIdStr = $studentInfo['student_id'] ?? '';

    // Also insert into rfid_cards table for lost/found tracking
    try {
        $stmt = $pdo->prepare('
            INSERT INTO rfid_cards (user_id, rfid_uid, registered_at, is_active)
            VALUES (?, ?, NOW(), 1)
            ON DUPLICATE KEY UPDATE rfid_uid = VALUES(rfid_uid), registered_at = NOW(), is_active = 1
        ');
        $stmt->execute([$data['student_id'], $rfid_uid]);
    } catch (\PDOException $e) {
        // Table might not exist yet, log but don't fail the registration
        error_log('Failed to insert into rfid_cards table: ' . $e->getMessage());
    }

    // Audit log
    $adminId = $_SESSION['admin_id'] ?? 0;
    $adminName = $_SESSION['admin_name'] ?? 'Admin';
    logAuditAction($pdo, $adminId, $adminName, 'REGISTER_RFID', 'student', $data['student_id'], $studentName,
        "Registered RFID card {$rfid_uid} to {$studentName} ({$studentIdStr})",
        ['rfid_uid' => $rfid_uid, 'student_id' => $studentIdStr]
    );

    echo json_encode(['success' => true]);

} catch (\PDOException $e) {
    error_log('RFID registration database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while registering card. Please try again.']);
} catch (\Exception $e) {
    error_log('RFID registration error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}