<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('student.delete', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission student.delete.',
]);

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
    
    if (!isset($data['student_id'])) {
        throw new \Exception('Missing student ID');
    }

    $pdo = pdo();
    
    // Fetch student info BEFORE deleting (for audit log)
    $infoStmt = $pdo->prepare('SELECT name, student_id, email, rfid_uid, course FROM users WHERE id = ? AND role = "Student"');
    $infoStmt->execute([$data['student_id']]);
    $studentInfo = $infoStmt->fetch(\PDO::FETCH_ASSOC);
    $studentName = $studentInfo['name'] ?? 'Unknown';
    $studentIdStr = $studentInfo['student_id'] ?? '';
    
    // Delete user record
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "Student"');
    $success = $stmt->execute([$data['student_id']]);

    if (!$success) {
        throw new \Exception('Failed to delete user record');
    }

    // Audit log
    $adminId = $_SESSION['admin_id'] ?? 0;
    $adminName = $_SESSION['admin_name'] ?? 'Admin';
    logAuditAction($pdo, $adminId, $adminName, 'DELETE_STUDENT', 'student', $data['student_id'], $studentName,
        "Deleted student account: {$studentName} ({$studentIdStr})",
        ['student_id' => $studentIdStr, 'email' => $studentInfo['email'] ?? '', 'rfid_uid' => $studentInfo['rfid_uid'] ?? '', 'course' => $studentInfo['course'] ?? '']
    );

    rotate_csrf_after_critical_action();

    echo json_encode(['success' => true]);

} catch (\Exception $e) {
    error_log('Delete account error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}