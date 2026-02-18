<?php

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start the session and include database connection
require_once __DIR__ . '/../db.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// CSRF protection for JSON API
$headers = getallheaders();
if (!isset($headers['X-CSRF-Token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $headers['X-CSRF-Token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['student_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Student ID is required']);
    exit();
}

$studentId = $input['student_id'];

try {
    $pdo = pdo();
    
    // First, check if violations column exists in users table
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS 
                           WHERE TABLE_SCHEMA = DATABASE() 
                           AND TABLE_NAME = 'users' 
                           AND COLUMN_NAME = 'violation_count'");
    $stmt->execute();
    $columnExists = $stmt->fetchColumn() > 0;
    
    if (!$columnExists) {
        // Add violation_count column if it doesn't exist
        $pdo->exec("ALTER TABLE users ADD COLUMN violation_count INT NOT NULL DEFAULT 0");
    }
    
    // Verify the student exists first
    $stmt = $pdo->prepare('SELECT id, name, violation_count FROM users WHERE id = ?');
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit();
    }
    
    $pdo->beginTransaction();
    
    try {
        // 1. Delete all violation records from the violations table
        //    (This is critical because a DB trigger recalculates violation_count from this table)
        $stmt = $pdo->prepare('DELETE FROM violations WHERE user_id = ?');
        $stmt->execute([$studentId]);
        $deletedViolations = $stmt->rowCount();
        
        // 2. Reset the violation count on the users table
        $stmt = $pdo->prepare('UPDATE users SET violation_count = 0 WHERE id = ?');
        $stmt->execute([$studentId]);
        
        $pdo->commit();
        
        // Log the action to audit trail
        try {
            $adminId = $_SESSION['admin_id'] ?? null;
            $stmt = $pdo->prepare("INSERT INTO audit_logs (admin_id, action, target_type, target_id, details) VALUES (?, 'clear_violations', 'user', ?, ?)");
            $stmt->execute([$adminId, $studentId, json_encode([
                'student_name' => $student['name'],
                'previous_violation_count' => $student['violation_count'],
                'violations_deleted' => $deletedViolations
            ])]);
        } catch (\PDOException $e) {
            error_log('Audit log error: ' . $e->getMessage());
        }
        
        echo json_encode(['success' => true, 'message' => 'Violations cleared successfully (' . $deletedViolations . ' records removed)']);
    } catch (\Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (\PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (\Exception $e) {
    error_log('Error clearing violations: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to clear violations']);
}
