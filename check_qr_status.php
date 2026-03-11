<?php

/**
 * Check QR Token Status API
 * Used by Digital ID page to check if QR code has been scanned
 * Enables auto-refresh when QR is verified
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
apply_cors_headers(['POST'], ['Content-Type', 'X-CSRF-Token']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Must be logged in as student
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$headers = getallheaders();
$csrfToken = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$data = get_json_input();
$token_hash = $data['token_hash'] ?? '';

if (empty($token_hash)) {
    echo json_encode(['error' => 'No token hash provided']);
    exit;
}

try {
    $pdo = pdo();
    
    // Check if this token has been used
    $stmt = $pdo->prepare('
        SELECT id, used_at, security_guard 
        FROM used_qr_tokens 
        WHERE token_hash = ? AND user_id = ? 
        LIMIT 1
    ');
    $stmt->execute([$token_hash, $_SESSION['user']['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo json_encode([
            'used' => true,
            'used_at' => $result['used_at'],
            'verified_by' => $result['security_guard']
        ]);
    } else {
        echo json_encode(['used' => false]);
    }
    
} catch (PDOException $e) {
    error_log('Token check error: ' . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
