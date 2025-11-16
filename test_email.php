<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

// Test email configuration
try {
    $test_email = SMTP_USER; // Using the same email as sender for testing
    $subject = 'PCU RFID System Test Email';
    $body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
            <img src="https://pcu.edu.ph/wp-content/uploads/2022/12/pcu-logo.png" alt="PCU Logo" style="display: block; margin: 0 auto; width: 150px;">
            <h2 style="color: #1e40af; text-align: center; margin-top: 20px;">PCU RFID System</h2>
            <p>Hello!</p>
            <p>This is a test email to verify that the PCU RFID System email functionality is working correctly.</p>
            <p>If you received this email, it means that:</p>
            <ul style="color: #4b5563;">
                <li>SMTP configuration is correct</li>
                <li>PHPMailer is properly installed and configured</li>
                <li>Email templates are rendering correctly</li>
            </ul>
            <div style="background-color: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <p style="margin: 0; text-align: center;">Test code: <span style="font-size: 24px; letter-spacing: 4px; font-family: monospace; color: #1e40af;">123456</span></p>
            </div>
            <p style="color: #6b7280; font-size: 14px; text-align: center; margin-top: 30px;">
                This is a test message from the PCU RFID System.<br>
                Please do not reply to this email.
            </p>
        </div>';

    echo "<h1>PCU RFID System - Email Test</h1>";
    echo "<p>Testing email configuration...</p>";
    
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new Exception("PHPMailer is not installed. Please run: composer require phpmailer/phpmailer");
    }

    echo "<h2>Current Configuration</h2>";
    echo "<pre>";
    echo "SMTP Host: " . SMTP_HOST . "\n";
    echo "SMTP Port: " . SMTP_PORT . "\n";
    echo "SMTP User: " . SMTP_USER . "\n";
    echo "From Name: " . SMTP_FROM_NAME . "\n";
    echo "Test Email: " . $test_email . "\n";
    echo "</pre>";

    echo "<h2>Test Results</h2>";
    $result = sendMail($test_email, $subject, $body, true);
    
    echo "<pre>";
    if ($result === true) {
        echo "✅ Success! Email sent successfully.\n\n";
        echo "Please check the inbox of: " . htmlspecialchars($test_email) . "\n";
        echo "You should receive an email with a sample 2FA code format.\n";
    } else {
        echo "❌ Error sending email:\n";
        echo htmlspecialchars($result) . "\n\n";
        echo "Troubleshooting Tips:\n";
        echo "1. Verify SMTP settings in db.php\n";
        echo "2. For Gmail:\n";
        echo "   - Enable 2-Step Verification\n";
        echo "   - Generate an App Password\n";
        echo "   - Use App Password instead of account password\n";
        echo "3. Check firewall/antivirus settings\n";
        echo "4. Verify port 587 is not blocked\n";
    }
    echo "</pre>";

} catch (Throwable $e) {
    echo "<h1>Error</h1>";
    echo "<pre>";
    echo "❌ Exception caught:\n";
    echo htmlspecialchars($e->getMessage()) . "\n\n";
    echo "Stack trace:\n";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}