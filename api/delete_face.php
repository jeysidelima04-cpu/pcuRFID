<?php

use PDO;
use Exception;

/**
 * API: Delete Face Descriptors for a Student
 * Endpoint: POST /api/delete_face.php
 * 
 * Security: Admin-only, CSRF protected, rate limited
 * Soft-deletes all face descriptors for a student.
 */
require_once __DIR__ . '/../db.php';

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
if (!check_rate_limit('face_delete', 20, 300)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $studentId = filter_var($input['student_id'] ?? null, FILTER_VALIDATE_INT);
    
    if (!$studentId || $studentId <= 0) {
        throw new Exception('Invalid student ID');
    }
    
    $pdo = pdo();
    
    // Verify student exists
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'Student'");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Student not found');
    }
    
    $pdo->beginTransaction();
    
    try {
        // Get count of active descriptors
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM face_descriptors WHERE user_id = ? AND is_active = 1");
        $stmt->execute([$studentId]);
        $descriptorCount = (int)$stmt->fetchColumn();
        
        if ($descriptorCount === 0) {
            throw new Exception('No active face descriptors found for this student');
        }
        
        // Soft delete - deactivate all descriptors
        $stmt = $pdo->prepare("UPDATE face_descriptors SET is_active = 0, updated_at = NOW() WHERE user_id = ? AND is_active = 1");
        $stmt->execute([$studentId]);
        
        // Update users table denormalized flag
        $stmt = $pdo->prepare("UPDATE users SET face_registered = 0, face_registered_at = NULL WHERE id = ?");
        $stmt->execute([$studentId]);
        
        // Log the deletion
        $stmt = $pdo->prepare("
            INSERT INTO face_registration_log 
            (user_id, action, descriptor_count, performed_by, ip_address, user_agent)
            VALUES (?, 'deactivated', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $studentId,
            $descriptorCount,
            $_SESSION['admin_id'],
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Deleted $descriptorCount face descriptor(s) for " . $student['name'],
            'deleted_count' => $descriptorCount
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('Face delete error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
