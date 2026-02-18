<?php

/**
 * API: Get Face Descriptors (Decrypted)
 * Endpoint: GET /api/get_face_descriptors.php
 * 
 * Security: Security guard OR Admin auth, CSRF protected, rate limited
 * Returns decrypted face descriptors for client-side matching at gate.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/encryption_helper.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ---- AUTH CHECK (Security Guard OR Admin) ----
$isSecurityGuard = isset($_SESSION['security_logged_in']) && $_SESSION['security_logged_in'] === true;
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

if (!$isSecurityGuard && !$isAdmin) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ---- CSRF CHECK ----
$headers = getallheaders();
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// ---- METHOD CHECK ----
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---- RATE LIMITING ----
if (!check_rate_limit('face_fetch', 20, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait.']);
    exit;
}

// ---- FEATURE CHECK ----
if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Face recognition is disabled']);
    exit;
}

try {
    $pdo = pdo();
    
    // Get all active face descriptors with student info
    $stmt = $pdo->prepare("
        SELECT 
            fd.id AS descriptor_id,
            fd.user_id,
            fd.descriptor_data,
            fd.descriptor_iv,
            fd.descriptor_tag,
            fd.label,
            fd.quality_score,
            u.name,
            u.student_id,
            u.email,
            u.profile_picture,
            u.violation_count,
            u.status
        FROM face_descriptors fd
        INNER JOIN users u ON fd.user_id = u.id
        WHERE fd.is_active = 1
          AND u.role = 'Student'
          AND u.status = 'Active'
        ORDER BY fd.user_id, fd.label
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $descriptors = [];
    $decryptionErrors = 0;
    
    foreach ($rows as $row) {
        try {
            // Decrypt the descriptor
            $decryptedJson = decrypt_descriptor(
                $row['descriptor_data'],
                $row['descriptor_iv'],
                $row['descriptor_tag']
            );
            
            $descriptorArray = json_decode($decryptedJson, true);
            
            if (!is_array($descriptorArray) || count($descriptorArray) !== 128) {
                error_log("Invalid descriptor format for user {$row['user_id']}, descriptor {$row['descriptor_id']}");
                $decryptionErrors++;
                continue;
            }
            
            $descriptors[] = [
                'user_id'         => (int)$row['user_id'],
                'name'            => $row['name'],
                'student_id'      => $row['student_id'],
                'email'           => $row['email'],
                'profile_picture' => $row['profile_picture'],
                'violation_count' => (int)$row['violation_count'],
                'label'           => $row['label'],
                'descriptor'      => $descriptorArray
            ];
        } catch (RuntimeException $e) {
            // Log but don't fail the entire request
            error_log("Descriptor decryption failed for user {$row['user_id']}: " . $e->getMessage());
            $decryptionErrors++;
        }
    }
    
    echo json_encode([
        'success'     => true,
        'descriptors' => $descriptors,
        'total'       => count($descriptors),
        'errors'      => $decryptionErrors,
        'threshold'   => (float)env('FACE_MATCH_THRESHOLD', '0.6'),
        'timestamp'   => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log('Get face descriptors error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error fetching descriptors']);
}
