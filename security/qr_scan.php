<?php
/**
 * QR Code Scan Verification API
 * Validates JWT tokens from Digital ID QR codes
 * Implements one-time use - tokens are invalidated after successful scan
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Check if security guard is logged in
if (!isset($_SESSION['security_logged_in'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Get the JWT token from request
$data = json_decode(file_get_contents('php://input'), true);
$jwt_token = trim($data['token'] ?? '');

if (empty($jwt_token)) {
    echo json_encode(['success' => false, 'error' => 'No QR code token provided']);
    exit;
}

try {
    $pdo = pdo();
    $jwt_secret = env('JWT_SECRET', 'pcurfid2-default-secret-change-in-production');
    
    // First check if this token has already been used
    $stmt = $pdo->prepare('SELECT id, used_at FROM used_qr_tokens WHERE token_hash = ? LIMIT 1');
    $token_hash = hash('sha256', $jwt_token);
    $stmt->execute([$token_hash]);
    $usedToken = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($usedToken) {
        echo json_encode([
            'success' => false,
            'error' => 'QR_ALREADY_USED',
            'message' => 'This QR code has already been scanned and verified',
            'used_at' => $usedToken['used_at'],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // Decode and verify JWT token
    try {
        $decoded = JWT::decode($jwt_token, new Key($jwt_secret, 'HS256'));
    } catch (ExpiredException $e) {
        echo json_encode([
            'success' => false,
            'error' => 'QR_EXPIRED',
            'message' => 'This QR code has expired. Please refresh your Digital ID.',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_QR',
            'message' => 'Invalid QR code',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // Extract student info from token
    $student_id = $decoded->student_id ?? null;
    $name = $decoded->name ?? null;
    $email = $decoded->email ?? null;
    
    if (!$student_id || !$email) {
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_TOKEN_DATA',
            'message' => 'QR code contains invalid data',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // Verify student exists in database
    $stmt = $pdo->prepare('
        SELECT id, student_id, name, email, profile_picture, status, created_at 
        FROM users 
        WHERE student_id = ? AND email = ? AND role = "Student" 
        LIMIT 1
    ');
    $stmt->execute([$student_id, $email]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode([
            'success' => false,
            'error' => 'STUDENT_NOT_FOUND',
            'message' => 'Student not found in the system',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // Check if student account is active
    if (isset($student['status']) && $student['status'] !== 'Active') {
        echo json_encode([
            'success' => false,
            'error' => 'ACCOUNT_INACTIVE',
            'message' => 'Student account is not active',
            'student' => [
                'name' => $student['name'],
                'student_id' => $student['student_id'],
                'status' => $student['status']
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // ✅ SUCCESS - Mark token as used (one-time use)
    $stmt = $pdo->prepare('
        INSERT INTO used_qr_tokens (token_hash, user_id, student_id, used_at, security_guard) 
        VALUES (?, ?, ?, NOW(), ?)
    ');
    $stmt->execute([
        $token_hash,
        $student['id'],
        $student['student_id'],
        $_SESSION['security_username'] ?? 'Unknown'
    ]);
    
    // Log the QR entry
    $stmt = $pdo->prepare('
        INSERT INTO qr_entry_logs (user_id, student_id, entry_type, scanned_at, security_guard) 
        VALUES (?, ?, "QR_CODE", NOW(), ?)
    ');
    $stmt->execute([
        $student['id'],
        $student['student_id'],
        $_SESSION['security_username'] ?? 'Unknown'
    ]);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'verified' => true,
        'student' => [
            'id' => $student['id'],
            'name' => $student['name'],
            'student_id' => $student['student_id'],
            'email' => $student['email'],
            'profile_picture' => $student['profile_picture'] ?? null,
            'status' => $student['status'] ?? 'Active'
        ],
        'message' => 'Student verified successfully via Digital ID',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    error_log('QR scan error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'DATABASE_ERROR',
        'message' => 'A database error occurred',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
