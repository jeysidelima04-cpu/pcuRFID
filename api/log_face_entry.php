<?php

use PDO;
use Exception;

/**
 * API: Log Face-Based Gate Entry
 * Endpoint: POST /api/log_face_entry.php
 * 
 * Security: Security guard auth, CSRF protected, rate limited
 * Records when a student enters via face recognition at the gate.
 * Follows same violation logic as gate_scan.php (RFID).
 */
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ---- AUTH CHECK (Security Guard only) ----
if (!isset($_SESSION['security_logged_in']) || $_SESSION['security_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Security login required']);
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
if (!check_rate_limit('face_entry', 60, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests']);
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
    
    $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
    $confidenceScore = filter_var($input['confidence_score'] ?? null, FILTER_VALIDATE_FLOAT);
    $matchThreshold = filter_var($input['match_threshold'] ?? 0.6, FILTER_VALIDATE_FLOAT);
    
    if (!$userId || $userId <= 0) {
        throw new Exception('Invalid user ID');
    }
    
    if ($confidenceScore === false || $confidenceScore < 0 || $confidenceScore > 1) {
        throw new Exception('Invalid confidence score');
    }
    
    $pdo = pdo();
    
    // Get security guard ID from session for audit trail
    $guardId = $_SESSION['security_id'] ?? null;
    
    // Get student info
    $stmt = $pdo->prepare("
        SELECT id, student_id, name, email, violation_count, profile_picture, status, rfid_uid
        FROM users 
        WHERE id = ? AND role = 'Student'
    ");
    $stmt->execute([$userId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Student not found');
    }
    
    if ($student['status'] !== 'Active') {
        echo json_encode([
            'success' => false,
            'access_denied' => true,
            'error' => 'ACCOUNT INACTIVE',
            'student' => [
                'id' => $student['id'],
                'name' => $student['name'],
                'student_id' => $student['student_id']
            ],
            'message' => 'Student account is not active. Contact administration.',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // Determine if student has physical ID (RFID registered)
    $hasRfid = !empty($student['rfid_uid']);
    
    $pdo->beginTransaction();
    
    try {
        // Determine entry type based on student's violation status
        $entryType = 'face_match'; // Default: successful face recognition entry
        
        // ⛔ CHECK IF STUDENT HAS REACHED MAXIMUM VIOLATIONS (3 strikes)
        if ($student['violation_count'] > 3) {
            $entryType = 'face_denied';
            
            // Log the denied entry
            $stmt = $pdo->prepare("
                INSERT INTO face_entry_logs 
                (user_id, confidence_score, match_threshold, entry_type, security_guard_id)
                VALUES (?, ?, ?, 'face_denied', ?)
            ");
            $stmt->execute([$userId, $confidenceScore, $matchThreshold, $guardId]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => false,
                'access_denied' => true,
                'entry_type' => 'face_denied',
                'student' => [
                    'id' => $student['id'],
                    'name' => $student['name'],
                    'student_id' => $student['student_id'],
                    'email' => $student['email'],
                    'violation_count' => $student['violation_count'],
                    'profile_picture' => $student['profile_picture'],
                    'severity' => 'blocked'
                ],
                'message' => 'MAXIMUM VIOLATION LIMIT REACHED - Entry DENIED. Contact administration.',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            exit;
        }
        
        // Student doesn't have RFID or forgot physical ID - this is a violation
        // Record violation same as RFID gate scan
        // NOTE: Face recognition at gate means student forgot physical ID
        $stmt = $pdo->prepare("
            INSERT INTO violations (user_id, rfid_uid, scanned_at, violation_type)
            VALUES (?, 'FACE_RECOGNITION', NOW(), 'forgot_card')
        ");
        $stmt->execute([$userId]);
        
        // Increment violation count
        $stmt = $pdo->prepare("UPDATE users SET violation_count = violation_count + 1 WHERE id = ?");
        $stmt->execute([$userId]);
        
        $newViolationCount = $student['violation_count'] + 1;
        
        // Log the face entry
        $stmt = $pdo->prepare("
            INSERT INTO face_entry_logs 
            (user_id, confidence_score, match_threshold, entry_type, security_guard_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $confidenceScore, $matchThreshold, $newViolationCount > 3 ? 'face_denied' : 'face_violation', $guardId]);
        
        $pdo->commit();
        
        // Send guardian notification (async - doesn't block)
        if (function_exists('send_guardian_entry_notification')) {
            send_guardian_entry_notification($userId, date('Y-m-d H:i:s'));
        }
        
        // ⛔ Check if now exceeds limit after increment
        if ($newViolationCount > 3) {
            echo json_encode([
                'success' => false,
                'access_denied' => true,
                'entry_type' => 'face_denied',
                'student' => [
                    'id' => $student['id'],
                    'name' => $student['name'],
                    'student_id' => $student['student_id'],
                    'email' => $student['email'],
                    'violation_count' => $newViolationCount,
                    'profile_picture' => $student['profile_picture'],
                    'severity' => 'blocked'
                ],
                'message' => 'MAXIMUM VIOLATION LIMIT REACHED - Entry DENIED. Contact administration.',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            exit;
        }
        
        // Determine severity
        $severity = 'low';
        $severityMessage = 'First warning - Remember to bring physical ID';
        
        if ($newViolationCount === 3) {
            $severity = 'critical';
            $severityMessage = 'FINAL WARNING - Next violation will result in entry denial';
        } elseif ($newViolationCount === 2) {
            $severity = 'medium';
            $severityMessage = 'Second strike - One more violation will trigger restriction';
        }
        
        echo json_encode([
            'success' => true,
            'entry_type' => 'face_violation',
            'student' => [
                'id' => $student['id'],
                'name' => $student['name'],
                'student_id' => $student['student_id'],
                'email' => $student['email'],
                'violation_count' => $newViolationCount,
                'severity' => $severity,
                'severity_message' => $severityMessage,
                'profile_picture' => $student['profile_picture']
            ],
            'confidence' => $confidenceScore,
            'message' => 'Face recognized - Student forgot physical ID (Violation recorded)',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('Face entry log error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
