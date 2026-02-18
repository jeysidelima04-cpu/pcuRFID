<?php

use PDO;
use Exception;

/**
 * Filter Audit Logs API
 * Returns filtered audit log entries based on criteria
 */

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Security check: Admin only
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = pdo();
    
    $query = "SELECT * FROM audit_logs WHERE 1=1";
    $params = [];
    
    // Filter by action type
    if (!empty($_GET['action_type'])) {
        $query .= " AND action_type = ?";
        $params[] = $_GET['action_type'];
    }
    
    // Filter by date from
    if (!empty($_GET['date_from'])) {
        $query .= " AND DATE(created_at) >= ?";
        $params[] = $_GET['date_from'];
    }
    
    // Filter by date to
    if (!empty($_GET['date_to'])) {
        $query .= " AND DATE(created_at) <= ?";
        $params[] = $_GET['date_to'];
    }
    
    // Filter by admin (optional)
    if (!empty($_GET['admin_id'])) {
        $query .= " AND admin_id = ?";
        $params[] = (int)$_GET['admin_id'];
    }
    
    $query .= " ORDER BY created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'count' => count($logs)
    ]);
    
} catch (Exception $e) {
    error_log("Audit filter error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to filter audit logs'
    ]);
}
