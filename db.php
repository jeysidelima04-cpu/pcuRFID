<?php
// db.php
declare(strict_types=1);

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF token helper
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            exit('Invalid CSRF token.');
        }
    }
}

/**
 * XSS Protection Helper - Escape output for safe HTML display
 * Use this for ALL user-generated content displayed in HTML
 * 
 * @param mixed $string The value to escape (handles null safely)
 * @return string The escaped string safe for HTML output
 */
function e($string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Database config
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'pcu_rfid2');  // Updated database name
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Enable error logging
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

// SMTP config (PHPMailer)
// Gmail SMTP configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'jeysidelima04@gmail.com');  // CHANGE THIS to your dummy Gmail address
define('SMTP_PASS', 'donx oasl cjsw eywx');  // CHANGE THIS to your Gmail app-specific password
define('SMTP_FROM', 'jeysidelima04@gmail.com');  // CHANGE THIS to your dummy Gmail address
define('SMTP_FROM_NAME', 'PCU RFID System');

function pdo(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;

    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        
        error_log("[PCU RFID] Attempting database connection to " . DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        error_log("[PCU RFID] Database connection successful");
        
        // Test if tables exist
        $tables = ['users', 'twofactor_codes'];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() === 0) {
                throw new Exception("Required table '$table' does not exist");
            }
        }
        return $pdo;
    } catch (PDOException $e) {
        error_log("[PCU RFID] Database error: " . $e->getMessage());
        throw $e;
    }
}

// Minimal mailer via PHPMailer
// Install with: composer require phpmailer/phpmailer
// Require Composer autoload if available; otherwise provide a helpful error when used.
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

function sendMail(string $to, string $subject, string $htmlBody, bool $returnError = false) {
    // If PHPMailer isn't installed via Composer, log and return false.
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        $msg = 'PHPMailer not installed. Run: composer require phpmailer/phpmailer';
        error_log($msg);
        return $returnError ? $msg : false;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = SMTP_PORT;

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $msg = 'Mail error: ' . $e->getMessage();
        error_log($msg);
        return $returnError ? $msg : false;
    }
}

/**
 * Rate limiting function - prevents brute force attacks
 * Uses session-based tracking (no database changes needed)
 * 
 * @param string $action Action identifier (e.g., 'login', 'reset_password')
 * @param int $max_attempts Maximum allowed attempts
 * @param int $time_window Time window in seconds (default 15 minutes)
 * @return bool True if allowed, false if rate limited
 */
function check_rate_limit(string $action, int $max_attempts = 5, int $time_window = 900): bool {
    // Initialize rate limit tracker in session if not exists
    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }
    
    $now = time();
    $identifier = $action . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    
    // Get attempts for this action
    if (!isset($_SESSION['rate_limits'][$identifier])) {
        $_SESSION['rate_limits'][$identifier] = [
            'attempts' => 0,
            'first_attempt' => $now,
            'blocked_until' => 0
        ];
    }
    
    $limit_data = &$_SESSION['rate_limits'][$identifier];
    
    // Check if currently blocked
    if ($limit_data['blocked_until'] > $now) {
        $remaining = $limit_data['blocked_until'] - $now;
        error_log("Rate limit: $action blocked for $remaining seconds");
        return false;
    }
    
    // Reset if time window expired
    if ($now - $limit_data['first_attempt'] > $time_window) {
        $limit_data['attempts'] = 0;
        $limit_data['first_attempt'] = $now;
        $limit_data['blocked_until'] = 0;
    }
    
    // Increment attempt counter
    $limit_data['attempts']++;
    
    // Block if exceeded max attempts
    if ($limit_data['attempts'] > $max_attempts) {
        $limit_data['blocked_until'] = $now + $time_window;
        error_log("Rate limit: $action exceeded $max_attempts attempts, blocked for $time_window seconds");
        return false;
    }
    
    return true;
}

/**
 * Reset rate limit for successful action
 * Call this after successful login/reset to clear the counter
 */
function reset_rate_limit(string $action): void {
    $identifier = $action . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (isset($_SESSION['rate_limits'][$identifier])) {
        unset($_SESSION['rate_limits'][$identifier]);
    }
}
