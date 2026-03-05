<?php

/**
 * API: Register Face Descriptor
 * Endpoint: POST /api/register_face.php
 * 
 * Security: Admin-only, CSRF protected, rate limited, encrypted storage
 * Receives a face descriptor from the admin panel and stores it encrypted.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/encryption_helper.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ---- AUTH CHECK ----
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Admin login required']);
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---- RATE LIMITING ----
if (!check_rate_limit('face_register', 30, 300)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait before trying again.']);
    exit;
}

// ---- FEATURE CHECK ----
if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Face recognition is disabled']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    // Validate required fields
    $studentId = filter_var($input['student_id'] ?? null, FILTER_VALIDATE_INT);
    $descriptor = $input['descriptor'] ?? null;
    $label = trim($input['label'] ?? 'front');
    $qualityScore = filter_var($input['quality_score'] ?? 0, FILTER_VALIDATE_FLOAT);
    
    if (!$studentId || $studentId <= 0) {
        throw new Exception('Invalid student ID');
    }
    
    if (!is_array($descriptor) || count($descriptor) !== 128) {
        throw new Exception('Invalid face descriptor - must be 128-dimensional array');
    }
    
    // Validate each descriptor value is a float
    foreach ($descriptor as $i => $val) {
        if (!is_numeric($val)) {
            throw new Exception("Invalid descriptor value at index $i");
        }
    }
    
    // Sanitize label
    $allowedLabels = ['front', 'left', 'right', 'up', 'down'];
    if (!in_array($label, $allowedLabels)) {
        $label = 'front';
    }
    
    // Validate quality score
    if ($qualityScore < 0 || $qualityScore > 1) {
        $qualityScore = 0;
    }
    
    $pdo = pdo();
    
    // Verify student exists and is active
    $stmt = $pdo->prepare("SELECT id, name, student_id, status FROM users WHERE id = ? AND role = 'Student'");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Student not found');
    }
    
    if ($student['status'] !== 'Active') {
        throw new Exception('Student account is not active');
    }
    
    // Check max descriptors per student
    $maxDescriptors = (int)env('FACE_MAX_DESCRIPTORS_PER_STUDENT', '5');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM face_descriptors WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$studentId]);
    $currentCount = (int)$stmt->fetchColumn();
    
    if ($currentCount >= $maxDescriptors) {
        throw new Exception("Maximum of $maxDescriptors face descriptors per student reached. Delete existing ones first.");
    }
    
    // Check for duplicate label
    $stmt = $pdo->prepare("SELECT id FROM face_descriptors WHERE user_id = ? AND label = ? AND is_active = 1");
    $stmt->execute([$studentId, $label]);
    if ($stmt->fetch()) {
        throw new Exception("A face descriptor with label '$label' already exists for this student. Choose a different angle or delete the existing one.");
    }
    
    // Encrypt the descriptor
    $descriptorJson = json_encode($descriptor);
    $encrypted = encrypt_descriptor($descriptorJson);
    
    // Store encrypted descriptor
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO face_descriptors 
            (user_id, descriptor_data, descriptor_iv, descriptor_tag, label, quality_score, registered_by, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $studentId,
            $encrypted['ciphertext'],
            $encrypted['iv'],
            $encrypted['tag'],
            $label,
            $qualityScore,
            $_SESSION['admin_id']
        ]);
        
        $descriptorId = $pdo->lastInsertId();
        
        // Update users table denormalized flag
        $stmt = $pdo->prepare("UPDATE users SET face_registered = 1, face_registered_at = NOW() WHERE id = ?");
        $stmt->execute([$studentId]);
        
        // Log the registration
        $stmt = $pdo->prepare("
            INSERT INTO face_registration_log 
            (user_id, action, descriptor_count, performed_by, ip_address, user_agent)
            VALUES (?, 'registered', 1, ?, ?, ?)
        ");
        $stmt->execute([
            $studentId,
            $_SESSION['admin_id'],
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Face descriptor ($label) registered for " . $student['name'],
            'descriptor_id' => $descriptorId,
            'total_descriptors' => $currentCount + 1
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (RuntimeException $e) {
    // Encryption errors
    error_log('Face registration encryption error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Encryption error - check server configuration']);
} catch (\PDOException $e) {
    error_log('Face registration database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while registering face descriptor. Please try again.']);
} catch (Exception $e) {
    error_log('Face registration error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
