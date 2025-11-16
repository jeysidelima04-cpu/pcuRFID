<?php
require_once __DIR__ . '/db.php';

$to = $argv[1] ?? getenv('TEST_MAIL_TO') ?: '';
if (empty($to)) {
    echo "Usage: php send_test_mail.php recipient@example.com\nOr set TEST_MAIL_TO env var.\n";
    exit(1);
}

$subject = 'PCU RFID Test Email';
$body = '<p>This is a test email from PCU RFID system.</p>';

echo "Sending test email to: $to\n";
$result = sendMail($to, $subject, $body, true);
if ($result === true) {
    echo "Mail sent successfully.\n";
    exit(0);
}

echo "Mail failed: " . (is_string($result) ? $result : 'unknown error') . "\n";
exit(2);
