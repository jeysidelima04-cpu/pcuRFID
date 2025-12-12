<?php
// admin/mark_lost_rfid.php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Verify CSRF token
verify_csrf();

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);
$cardId = (int)($data['card_id'] ?? 0);
$action = trim($data['action'] ?? ''); // 'mark_lost' or 'mark_found'
$reason = trim($data['reason'] ?? '');

if (!$cardId || !in_array($action, ['mark_lost', 'mark_found'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

// Get admin user ID
$adminId = $_SESSION['admin_id'] ?? 0;

if (!$adminId) {
    echo json_encode(['success' => false, 'error' => 'Admin ID not found in session']);
    exit;
}

try {
    if ($action === 'mark_lost') {
        if (empty($reason)) {
            echo json_encode(['success' => false, 'error' => 'Please provide a reason for marking as lost']);
            exit;
        }
        
        $result = mark_rfid_lost($cardId, $adminId, $reason);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'RFID card marked as lost successfully',
                'action' => 'marked_lost'
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to mark RFID as lost']);
        }
    } elseif ($action === 'mark_found') {
        $result = mark_rfid_found($cardId, $adminId);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'RFID card marked as found successfully',
                'action' => 'marked_found'
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to mark RFID as found']);
        }
    }
} catch (Exception $e) {
    error_log('Error in mark_lost_rfid.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while processing your request'
    ]);
}
