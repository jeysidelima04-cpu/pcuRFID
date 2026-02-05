<?php
require_once 'db.php';

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'google_id'");
    $result = $stmt->fetch();
    
    if ($result) {
        echo "✓ google_id column EXISTS\n";
        print_r($result);
    } else {
        echo "✗ google_id column MISSING - Need to run add_google_id_column.sql\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
