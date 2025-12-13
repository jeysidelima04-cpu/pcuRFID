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
        // Check for CSRF token in POST data, JSON body, or HTTP header
        $token = $_POST['csrf_token'] ?? '';
        
        // If not in POST, check JSON body
        if (empty($token)) {
            $json = json_decode(file_get_contents('php://input'), true);
            $token = $json['csrf_token'] ?? '';
        }
        
        // If not in JSON body, check HTTP header
        if (empty($token)) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }
        
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

/**
 * Load environment variables from .env file
 */
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $env = parse_ini_file($envFile);
} else {
    // Fallback for backward compatibility
    $env = [];
}

/**
 * Helper function to get environment variable with fallback
 */
if (!function_exists('env')) {
    function env($key, $default = '') {
        global $env;
        return isset($env[$key]) ? $env[$key] : $default;
    }
}

// Database config (load from .env for security)
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_NAME', env('DB_NAME', 'pcu_rfid2'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// Enable error logging
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

// SMTP config (load from .env for security)
define('SMTP_HOST', env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', env('SMTP_PORT', 587));
define('SMTP_USER', env('SMTP_USER', 'jeysidelima04@gmail.com'));
define('SMTP_PASS', env('SMTP_PASS', ''));
define('SMTP_FROM', env('SMTP_FROM', 'jeysidelima04@gmail.com'));
define('SMTP_FROM_NAME', env('SMTP_FROM_NAME', 'PCU RFID System'));

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

// ============================================================================
// PHASE 1: LOST RFID TRACKING HELPER FUNCTIONS
// ============================================================================

/**
 * Check if RFID card is active and NOT lost for a student
 * Used by gate_scan.php to determine if violations should be recorded
 * 
 * @param int $studentId User ID of the student
 * @param string $rfidUid RFID card UID
 * @return bool True if card is Active and not lost, false otherwise
 */
function is_rfid_active_for_student(int $studentId, string $rfidUid): bool {
    try {
        $pdo = pdo();
        $stmt = $pdo->prepare("
            SELECT status, is_lost 
            FROM rfid_cards 
            WHERE user_id = ? AND rfid_uid = ?
            LIMIT 1
        ");
        $stmt->execute([$studentId, $rfidUid]);
        $card = $stmt->fetch();
        
        if (!$card) {
            return false;
        }
        
        // Card must be Active AND not lost
        return ($card['status'] === 'Active' && $card['is_lost'] == 0);
    } catch (PDOException $e) {
        error_log("Error checking RFID status: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if an RFID card is marked as lost
 * 
 * @param string $rfidUid RFID card UID
 * @return array|null Returns card info if lost, null otherwise
 */
function is_rfid_lost(string $rfidUid): ?array {
    try {
        $pdo = pdo();
        $stmt = $pdo->prepare("
            SELECT r.*, u.student_id, u.first_name, u.last_name,
                   admin.first_name AS reported_by_first_name,
                   admin.last_name AS reported_by_last_name
            FROM rfid_cards r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN users admin ON r.lost_reported_by = admin.id
            WHERE r.rfid_uid = ? AND r.is_lost = 1
            LIMIT 1
        ");
        $stmt->execute([$rfidUid]);
        $card = $stmt->fetch();
        
        return $card ?: null;
    } catch (PDOException $e) {
        error_log("Error checking if RFID is lost: " . $e->getMessage());
        return null;
    }
}

/**
 * Mark an RFID card as lost
 * 
 * @param int $cardId RFID card ID
 * @param int $adminId Admin user ID who is marking it lost
 * @param string $reason Reason for marking as lost
 * @return bool True on success, false on failure
 */
function mark_rfid_lost(int $cardId, int $adminId, string $reason): bool {
    try {
        $pdo = pdo();
        $pdo->beginTransaction();
        
        // Update rfid_cards table
        $stmt = $pdo->prepare("
            UPDATE rfid_cards 
            SET is_lost = 1, 
                lost_at = NOW(), 
                lost_reason = ?, 
                lost_reported_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$reason, $adminId, $cardId]);
        
        // Log to audit history
        $stmt = $pdo->prepare("
            INSERT INTO rfid_status_history 
            (rfid_card_id, user_id, status_change, changed_at, changed_by, reason, ip_address)
            SELECT id, user_id, 'LOST', NOW(), ?, ?, ?
            FROM rfid_cards WHERE id = ?
        ");
        $stmt->execute([$adminId, $reason, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $cardId]);
        
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error marking RFID as lost: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark an RFID card as found (unmark lost)
 * 
 * @param int $cardId RFID card ID
 * @param int $adminId Admin user ID who is marking it found
 * @return bool True on success, false on failure
 */
function mark_rfid_found(int $cardId, int $adminId): bool {
    try {
        $pdo = pdo();
        $pdo->beginTransaction();
        
        // Get lost reason for audit log
        $stmt = $pdo->prepare("SELECT lost_reason FROM rfid_cards WHERE id = ?");
        $stmt->execute([$cardId]);
        $previousReason = $stmt->fetchColumn() ?: '';
        
        // Update rfid_cards table
        $stmt = $pdo->prepare("
            UPDATE rfid_cards 
            SET is_lost = 0, 
                found_at = NOW(), 
                found_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$adminId, $cardId]);
        
        // Log to audit history
        $stmt = $pdo->prepare("
            INSERT INTO rfid_status_history 
            (rfid_card_id, user_id, status_change, changed_at, changed_by, reason, notes, ip_address)
            SELECT id, user_id, 'FOUND', NOW(), ?, ?, 'Previously lost', ?
            FROM rfid_cards WHERE id = ?
        ");
        $stmt->execute([$adminId, $previousReason, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $cardId]);
        
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error marking RFID as found: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// PHASE 2: GUARDIAN NOTIFICATION HELPER FUNCTIONS
// ============================================================================

/**
 * Check if guardian notifications are globally enabled
 * 
 * @return bool True if enabled, false otherwise
 */
function are_guardian_notifications_enabled(): bool {
    try {
        $pdo = pdo();
        $stmt = $pdo->query("SELECT value FROM system_settings WHERE setting_key = 'guardian_notifications_enabled' LIMIT 1");
        $result = $stmt->fetchColumn();
        return $result === '1' || $result === 'true';
    } catch (PDOException $e) {
        // If table doesn't exist yet (before Phase 2 migration), return false
        return false;
    }
}

/**
 * Check if a notification can be sent (rate limiting - 10 minutes)
 * 
 * @param int $studentId Student user ID
 * @param string $type Notification type (e.g., 'entry')
 * @return bool True if notification can be sent, false if rate limited
 */
function can_send_guardian_notification(int $studentId, string $type = 'entry'): bool {
    try {
        $pdo = pdo();
        $stmt = $pdo->prepare("
            SELECT sent_at 
            FROM notification_logs 
            WHERE student_id = ? AND notification_type = ? 
            ORDER BY sent_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$studentId, $type]);
        $lastSent = $stmt->fetchColumn();
        
        if (!$lastSent) {
            return true; // No previous notification
        }
        
        // Check if 10 minutes have passed
        $lastSentTime = strtotime($lastSent);
        $currentTime = time();
        $timeDiff = $currentTime - $lastSentTime;
        
        return $timeDiff >= 600; // 10 minutes = 600 seconds
    } catch (PDOException $e) {
        error_log("Error checking notification rate limit: " . $e->getMessage());
        return false; // Fail safe - don't send if error
    }
}

/**
 * Send entry notification to student's guardians
 * 
 * @param int $studentId Student user ID
 * @param string $entryTime Entry timestamp
 * @return bool True if at least one notification sent, false otherwise
 */
function send_guardian_entry_notification(int $studentId, string $entryTime): bool {
    // Check global setting
    if (!are_guardian_notifications_enabled()) {
        return false;
    }
    
    // Check rate limiting
    if (!can_send_guardian_notification($studentId, 'entry')) {
        error_log("Guardian notification rate limited for student ID: $studentId");
        return false;
    }
    
    try {
        $pdo = pdo();
        
        // Get student info
        $stmt = $pdo->prepare("
            SELECT first_name, last_name, student_id, course, year_level 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        
        if (!$student) {
            return false;
        }
        
        // Get active guardians with notifications enabled
        $stmt = $pdo->prepare("
            SELECT g.id, g.email, g.first_name, g.last_name, ns.entry_notification
            FROM guardians g
            JOIN student_guardians sg ON g.id = sg.guardian_id
            LEFT JOIN notification_settings ns ON g.id = ns.guardian_id
            WHERE sg.student_id = ? 
              AND sg.is_primary = 1
              AND (ns.entry_notification IS NULL OR ns.entry_notification = 1)
        ");
        $stmt->execute([$studentId]);
        $guardians = $stmt->fetchAll();
        
        if (empty($guardians)) {
            return false;
        }
        
        $sentCount = 0;
        foreach ($guardians as $guardian) {
            // Queue notification
            $stmt = $pdo->prepare("
                INSERT INTO notification_queue 
                (student_id, guardian_id, notification_type, scheduled_for, data)
                VALUES (?, ?, 'entry', NOW(), ?)
            ");
            
            $data = json_encode([
                'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                'student_id' => $student['student_id'],
                'course' => $student['course'],
                'year_level' => $student['year_level'],
                'entry_time' => $entryTime
            ]);
            
            $stmt->execute([$studentId, $guardian['id'], $data]);
            
            // Send email immediately
            $subject = "Campus Entry Alert - " . $student['first_name'] . " " . $student['last_name'];
            $body = "
                <h2>Campus Entry Notification</h2>
                <p>Dear {$guardian['first_name']} {$guardian['last_name']},</p>
                <p>This is to inform you that your child/ward has entered the campus:</p>
                <ul>
                    <li><strong>Student Name:</strong> {$student['first_name']} {$student['last_name']}</li>
                    <li><strong>Student ID:</strong> {$student['student_id']}</li>
                    <li><strong>Course:</strong> {$student['course']}</li>
                    <li><strong>Year Level:</strong> {$student['year_level']}</li>
                    <li><strong>Entry Time:</strong> $entryTime</li>
                </ul>
                <p>This is an automated notification from the PCU RFID System.</p>
                <p>To manage notification preferences, please contact the admin office.</p>
            ";
            
            $emailSent = sendMail($guardian['email'], $subject, $body);
            
            if ($emailSent) {
                // Log successful notification
                $stmt = $pdo->prepare("
                    INSERT INTO notification_logs 
                    (student_id, guardian_id, notification_type, sent_at, status)
                    VALUES (?, ?, 'entry', NOW(), 'sent')
                ");
                $stmt->execute([$studentId, $guardian['id']]);
                
                // Update queue status
                $stmt = $pdo->prepare("
                    UPDATE notification_queue 
                    SET status = 'sent', sent_at = NOW() 
                    WHERE student_id = ? AND guardian_id = ? AND notification_type = 'entry' AND status = 'pending'
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$studentId, $guardian['id']]);
                
                $sentCount++;
            } else {
                // Log failed notification
                $stmt = $pdo->prepare("
                    INSERT INTO notification_logs 
                    (student_id, guardian_id, notification_type, sent_at, status)
                    VALUES (?, ?, 'entry', NOW(), 'failed')
                ");
                $stmt->execute([$studentId, $guardian['id']]);
            }
        }
        
        return $sentCount > 0;
    } catch (PDOException $e) {
        error_log("Error sending guardian notification: " . $e->getMessage());
        return false;
    }
}
