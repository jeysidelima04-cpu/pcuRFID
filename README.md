PCU RFID — Mail test

1. Install dependencies (if not already):

```powershell
cd C:\xampp\htdocs\pcuRFID2
composer install
```

2. Configure SMTP credentials via environment variables (optional):

- `PCU_SMTP_HOST` (default: smtp.yourdomain.com)
- `PCU_SMTP_PORT` (default: 587)
- `PCU_SMTP_USER` (default: no-reply@yourdomain.com)
- `PCU_SMTP_PASS` (default: your-strong-smtp-password)
- `PCU_SMTP_FROM` (default: no-reply@yourdomain.com)
- `PCU_SMTP_FROM_NAME` (default: PCU RFID System)

3. Send a test email:

```powershell
# one-off:
php send_test_mail.php recipient@example.com

# or using env var:
$env:TEST_MAIL_TO = 'recipient@example.com'; php send_test_mail.php
```

If sending fails, the script prints the PHPMailer error message to help debugging.