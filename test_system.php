<?php
require_once 'db.php';

try {
    // Test database connection
    $pdo = pdo();
    echo "Database connection successful!<br>";

    // Test tables exist
    $tables = ['users', 'twofactor_codes'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "Table '$table' exists<br>";
        } else {
            echo "Table '$table' does not exist!<br>";
        }
    }

    // Test email
    $testResult = sendMail(
        SMTP_USER, // Send to yourself for testing
        'PCU RFID System - Test Email',
        '<h1>Test Email</h1><p>If you receive this email, the SMTP configuration is working correctly.</p>'
    );

    if ($testResult === true) {
        echo "Test email sent successfully!<br>";
    } else {
        echo "Email error: " . $testResult . "<br>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}