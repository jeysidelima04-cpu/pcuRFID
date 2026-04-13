<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('rfid.register', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission rfid.register.',
]);

// CSRF protection for JSON API
$headers = function_exists('getallheaders') ? getallheaders() : [];
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Check if request is POST and has JSON content
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$rawBody = get_raw_request_body();
if ($rawBody === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$pdo = null;

try {
    $data = json_decode($rawBody, true);
    
    if (!isset($data['student_id']) || !isset($data['rfid_uid'])) {
        throw new \Exception('Missing required fields');
    }

    $studentUserId = filter_var($data['student_id'], FILTER_VALIDATE_INT);
    if (!$studentUserId || $studentUserId <= 0) {
        throw new \Exception('Invalid student ID');
    }

    $resolvedStudentCode = trim((string)($data['student_code'] ?? ''));
    if ($resolvedStudentCode !== '' && !preg_match('/^\d{9}$/', $resolvedStudentCode)) {
        throw new \Exception('Student ID must be exactly 9 digits (numbers only).');
    }

    // Preserve scanned value (no added/removed digits) and enforce strict format.
    $rfid_uid = trim((string)($data['rfid_uid'] ?? ''));
    
    // Avoid logging full RFID values to reduce sensitive data exposure.
    error_log('RFID Scanner input received. Length: ' . strlen($rfid_uid));
    
    // Validate format - R20XC-USB outputs 10-digit decimal numbers
    if (strlen($rfid_uid) !== 10) {
        throw new \Exception('Invalid RFID length. RFID UID must be exactly 10 digits.');
    }
    
    // R20XC-USB card UID format: exactly 10 numeric digits.
    if (!preg_match('/^\d{10}$/', $rfid_uid)) {
        throw new \Exception('Invalid RFID format. RFID UID must contain numbers only (0-9).');
    }

    $pdo = pdo();
    $pdo->beginTransaction();

    // Validate target user is an existing student before any write operations.
    $studentStmt = $pdo->prepare('SELECT id, name, student_id, status, deleted_at FROM users WHERE id = ? AND role = "Student" LIMIT 1 FOR UPDATE');
    $studentStmt->execute([$studentUserId]);
    $studentInfo = $studentStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$studentInfo) {
        throw new \Exception('Student not found');
    }

    if (!empty($studentInfo['deleted_at'])) {
        throw new \Exception('Student account is archived and cannot be registered.');
    }

    if (($studentInfo['status'] ?? '') !== 'Active') {
        throw new \Exception('Student account is not active.');
    }

    // For newly verified students with temporary IDs, allow saving the real 9-digit ID
    // during first RFID registration (after realtime availability check in pre-check flow).
    if ($resolvedStudentCode !== '') {
        $currentStudentCode = (string)($studentInfo['student_id'] ?? '');
        if ($resolvedStudentCode !== $currentStudentCode) {
            $isTemporaryCode = strncmp($currentStudentCode, 'TEMP-', 5) === 0;
            if (!$isTemporaryCode) {
                throw new \Exception('Student ID update is only allowed for temporary IDs.');
            }

            $dupStmt = $pdo->prepare('SELECT id FROM users WHERE student_id = ? AND id != ? LIMIT 1');
            $dupStmt->execute([$resolvedStudentCode, $studentUserId]);
            if ($dupStmt->fetch()) {
                throw new \Exception('This student ID is already taken.');
            }

            $updateStudentCodeStmt = $pdo->prepare('UPDATE users SET student_id = ? WHERE id = ? AND role = "Student"');
            $updateStudentCodeStmt->execute([$resolvedStudentCode, $studentUserId]);
            $studentInfo['student_id'] = $resolvedStudentCode;
        }
    }
    
    // Check if RFID is already registered to another student in users table.
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE role = 'Student' AND id != ? AND rfid_uid = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$studentUserId, $rfid_uid]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        throw new \Exception('This card is already registered to another student.');
    }

    // Extra hardening for legacy malformed values (e.g., accidental extra digit)
    // so a corrupted old enrollment cannot be bypassed with a 10-digit retry.
    $legacyOverlapStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'Student' AND id != ? AND rfid_uid IS NOT NULL AND rfid_uid <> ? AND (rfid_uid LIKE CONCAT(?, '%') OR ? LIKE CONCAT(rfid_uid, '%')) LIMIT 1 FOR UPDATE");
    $legacyOverlapStmt->execute([$studentUserId, $rfid_uid, $rfid_uid, $rfid_uid]);
    if ($legacyOverlapStmt->fetch()) {
        throw new \Exception('A similar enrolled RFID UID already exists (possible malformed previous registration). Please fix the existing card record first.');
    }

    // Also check rfid_cards to guard against denormalized table drift.
    try {
        $cardStmt = $pdo->prepare("SELECT id, user_id FROM rfid_cards WHERE user_id != ? AND rfid_uid = ? LIMIT 1 FOR UPDATE");
        $cardStmt->execute([$studentUserId, $rfid_uid]);
        if ($cardStmt->fetch()) {
            throw new \Exception('This card is already registered to another student.');
        }

        $cardLegacyOverlapStmt = $pdo->prepare("SELECT id, user_id FROM rfid_cards WHERE user_id != ? AND rfid_uid <> ? AND (rfid_uid LIKE CONCAT(?, '%') OR ? LIKE CONCAT(rfid_uid, '%')) LIMIT 1 FOR UPDATE");
        $cardLegacyOverlapStmt->execute([$studentUserId, $rfid_uid, $rfid_uid, $rfid_uid]);
        if ($cardLegacyOverlapStmt->fetch()) {
            throw new \Exception('A similar enrolled RFID UID already exists (possible malformed previous registration). Please fix the existing card record first.');
        }
    } catch (\PDOException $e) {
        // Table/column may be unavailable on legacy installs; proceed with users-table enforcement.
        error_log('register_card duplicate check (rfid_cards) warning: ' . $e->getMessage());
    }

    // Update user record with RFID UID, set registration timestamp, and status to Active
    $stmt = $pdo->prepare('UPDATE users SET rfid_uid = ?, rfid_registered_at = NOW(), status = "Active" WHERE id = ? AND role = "Student"');
    $success = $stmt->execute([$rfid_uid, $studentUserId]);

    if (!$success || $stmt->rowCount() === 0) {
        throw new \Exception('Student not found or failed to update record');
    }

    $studentName = $studentInfo['name'] ?? 'Unknown';
    $studentIdStr = $studentInfo['student_id'] ?? '';

    // Also insert into rfid_cards table for lost/found tracking
    try {
        $stmt = $pdo->prepare('
            INSERT INTO rfid_cards (user_id, rfid_uid, registered_at, is_active)
            VALUES (?, ?, NOW(), 1)
            ON DUPLICATE KEY UPDATE rfid_uid = VALUES(rfid_uid), registered_at = NOW(), is_active = 1
        ');
        $stmt->execute([$studentUserId, $rfid_uid]);
    } catch (\PDOException $e) {
        $isDuplicateKey = ((string)$e->getCode() === '23000') || ((int)($e->errorInfo[1] ?? 0) === 1062);
        if ($isDuplicateKey) {
            throw new \Exception('This card is already registered to another student.');
        }

        // Table might not exist yet on legacy installs; users table remains authoritative.
        error_log('Failed to insert into rfid_cards table: ' . $e->getMessage());
    }

    // Audit log
    $adminId = $_SESSION['admin_id'] ?? 0;
    $adminName = $_SESSION['admin_name'] ?? 'Admin';
    logAuditAction($pdo, $adminId, $adminName, 'REGISTER_RFID', 'student', $studentUserId, $studentName,
        "Registered RFID card {$rfid_uid} to {$studentName} ({$studentIdStr})",
        ['rfid_uid' => $rfid_uid, 'student_id' => $studentIdStr]
    );

    $pdo->commit();
    rotate_csrf_after_critical_action();

    echo json_encode(['success' => true]);

} catch (\PDOException $e) {
    if ($pdo instanceof \PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $isDuplicateKey = ((string)$e->getCode() === '23000') || ((int)($e->errorInfo[1] ?? 0) === 1062);
    if ($isDuplicateKey) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This card is already registered to another student.']);
        exit;
    }

    error_log('RFID registration database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while registering card. Please try again.']);
} catch (\Exception $e) {
    if ($pdo instanceof \PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('RFID registration error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}