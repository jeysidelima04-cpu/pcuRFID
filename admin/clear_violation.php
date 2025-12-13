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
    
    // Clear the violation count for the student
    $stmt = $pdo->prepare('UPDATE users SET violation_count = 0 WHERE id = ?');
    $stmt->execute([$studentId]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Violations cleared successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Student not found or no changes made']);
    }
    
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log('Error clearing violations: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to clear violations']);
}
