<?php

require_once __DIR__ . '/../db.php';
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json');
ob_start(); // Enable output buffering for deferred email sending

// Security: Prevent caching of sensitive data
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Check if security guard is logged in
if (!isset($_SESSION['security_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// CSRF Protection - Validate CSRF token for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $providedToken = $data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    
    if (empty($sessionToken) || !hash_equals($sessionToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// Receive RFID tap from scanner
$data = json_decode(file_get_contents('php://input'), true);
$rfid_uid = trim($data['rfid_uid'] ?? '');

if (!$rfid_uid) {
    echo json_encode(['success' => false, 'error' => 'No RFID UID provided']);
    exit;
}

// Helper: flush JSON response to client immediately, then continue for background work
function flushJsonResponse($data) {
    $json = json_encode($data);
    ignore_user_abort(true);
    header('Content-Length: ' . strlen($json));
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ob_end_flush();
        flush();
    }
}

try {
    $pdo = pdo();
    
    // Find student with this RFID card - exact match (fast indexed query)
    $stmt = $pdo->prepare('
        SELECT id, student_id, name, email, rfid_uid, violation_count, profile_picture, course
        FROM users 
        WHERE rfid_uid = ? AND role = "Student"
    ');
    $stmt->execute([$rfid_uid]);
    $student = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if (!$student) {
        // Try case-insensitive match as fallback
        $stmt = $pdo->prepare('
            SELECT id, student_id, name, email, rfid_uid, violation_count, profile_picture, course
            FROM users 
            WHERE LOWER(rfid_uid) = LOWER(?) AND role = "Student"
        ');
        $stmt->execute([$rfid_uid]);
        $student = $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    if (!$student) {
        echo json_encode([
            'success' => false, 
            'error' => 'Unknown RFID card',
            'message' => 'This card is not registered in the system'
        ]);
        exit;
    }
    
    // 🔍 PHASE 1: CHECK IF RFID CARD IS MARKED AS LOST
    $lostCard = is_rfid_lost($rfid_uid);
    if ($lostCard) {
        echo json_encode([
            'success' => false,
            'is_lost' => true,
            'error' => 'RFID CARD REPORTED AS LOST',
            'student' => [
                'id' => $student['id'],
                'name' => $student['name'],
                'student_id' => $student['student_id']
            ],
            'lost_info' => [
                'lost_at' => $lostCard['lost_at'],
                'lost_reason' => $lostCard['lost_reason'],
                'reported_by' => ($lostCard['reported_by_first_name'] ?? '') . ' ' . ($lostCard['reported_by_last_name'] ?? '')
            ],
            'message' => 'This RFID card has been reported as LOST. Please contact the administration office.',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // ✅ RECORD THE VIOLATION (student forgot physical ID, using RFID backup)
    $stmt = $pdo->prepare('
        INSERT INTO violations (user_id, rfid_uid, scanned_at) 
        VALUES (?, ?, NOW())
    ');
    $stmt->execute([$student['id'], $rfid_uid]);
    
    // ✅ INCREMENT VIOLATION COUNT
    $stmt = $pdo->prepare('
        UPDATE users 
        SET violation_count = violation_count + 1 
        WHERE id = ?
    ');
    $stmt->execute([$student['id']]);
    
    // Get updated violation count
    $newViolationCount = $student['violation_count'] + 1;
    $entryTime = date('Y-m-d H:i:s');
    
    // ⛔ CHECK IF STUDENT HAS REACHED MAXIMUM VIOLATIONS (3 strikes)
    if ($newViolationCount > 3) {
        // Send response FIRST, emails after
        flushJsonResponse([
            'success' => false,
            'access_denied' => true,
            'error' => 'ACCESS DENIED',
            'student' => [
                'id' => $student['id'],
                'name' => $student['name'],
                'student_id' => $student['student_id'],
                'email' => $student['email'],
                'violation_count' => $student['violation_count'],
                'severity' => 'blocked'
            ],
            'message' => 'MAXIMUM VIOLATION LIMIT REACHED - Entry to school is DENIED. Contact administration office.',
            'timestamp' => $entryTime
        ]);
        // Background: send emails after response is already delivered
        sendViolationEmail($student, $newViolationCount, 'denied');
        send_guardian_entry_notification($student['id'], $entryTime);
        exit;
    }
    
    // Determine severity level
    $severity = 'low';
    $severityMessage = 'First warning';
    $emailSeverity = 'first';
    
    if ($newViolationCount === 3) {
        $severity = 'critical';
        $severityMessage = 'FINAL WARNING - Next violation will result in entry denial';
        $emailSeverity = 'critical';
    } elseif ($newViolationCount === 2) {
        $severity = 'medium';
        $severityMessage = 'Second strike - One more violation will trigger restriction';
        $emailSeverity = 'warning';
    } else {
        $severity = 'low';
        $severityMessage = 'First warning - Remember to bring physical ID';
        $emailSeverity = 'first';
    }
    
    // 🚀 Send JSON response IMMEDIATELY — emails sent AFTER client receives response
    flushJsonResponse([
        'success' => true,
        'student' => [
            'id' => $student['id'],
            'name' => $student['name'],
            'student_id' => $student['student_id'],
            'email' => $student['email'],
            'rfid_uid' => $rfid_uid,
            'violation_count' => $newViolationCount,
            'severity' => $severity,
            'severity_message' => $severityMessage,
            'profile_picture' => $student['profile_picture'] ?? null,
            'course' => $student['course'] ?? null
        ],
        'message' => 'Entry allowed - Student forgot physical ID',
        'timestamp' => $entryTime
    ]);
    
    // 📧 Background: send emails AFTER response is delivered to client
    sendViolationEmail($student, $newViolationCount, $emailSeverity);
    send_guardian_entry_notification($student['id'], $entryTime);
    
} catch (\PDOException $e) {
    error_log('Gate scan error: ' . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error occurred',
        'details' => $e->getMessage()
    ]);
}

/**
 * Send email notification to student about violation
 */
function sendViolationEmail($student, $violationCount, $severity) {
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'mrk.briones118@gmail.com'; // Your Gmail
        $mail->Password = 'epbiboyqhgtnlzoo'; // Your App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 10;       // 10 second connection timeout
        $mail->SMTPKeepAlive = true; // Reuse connection if multiple sends
        
        // Recipients
        $mail->setFrom('mrk.briones118@gmail.com', 'PCU RFID Security System');
        $mail->addAddress($student['email'], $student['name']);
        
        // Email content based on severity
        $subject = '';
        $body = '';
        $timestamp = date('F j, Y g:i A');
        
        if ($severity === 'denied') {
            $subject = '🚫 ACCESS DENIED - Maximum Violations Reached';
            $body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <div style='background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                        <h1 style='margin: 0; font-size: 28px;'>⛔ ACCESS DENIED</h1>
                    </div>
                    <div style='background: #ffffff; padding: 30px; border: 2px solid #dc2626; border-top: none; border-radius: 0 0 10px 10px;'>
                        <p style='font-size: 16px; color: #1f2937; margin-bottom: 20px;'>Dear <strong>{$student['name']}</strong>,</p>
                        
                        <div style='background: #fee2e2; border-left: 4px solid #dc2626; padding: 15px; margin: 20px 0;'>
                            <p style='color: #991b1b; font-size: 18px; font-weight: bold; margin: 0;'>🚨 ENTRY TO SCHOOL IS DENIED</p>
                        </div>
                        
                        <p style='color: #374151; line-height: 1.6;'>You have exceeded the maximum violation limit for forgetting your physical student ID card.</p>
                        
                        <div style='background: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                            <table style='width: 100%; border-collapse: collapse;'>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Student ID:</td>
                                    <td style='padding: 8px 0; color: #111827; font-weight: bold;'>{$student['student_id']}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Total Violations:</td>
                                    <td style='padding: 8px 0; color: #dc2626; font-weight: bold; font-size: 20px;'>{$violationCount}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Date & Time:</td>
                                    <td style='padding: 8px 0; color: #111827;'>{$timestamp}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Status:</td>
                                    <td style='padding: 8px 0; color: #dc2626; font-weight: bold;'>🔒 ENTRY BLOCKED</td>
                                </tr>
                            </table>
                        </div>
                        
                        <div style='background: #fef3c7; border: 2px solid #f59e0b; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                            <p style='color: #92400e; margin: 0; font-weight: bold;'>⚠️ IMMEDIATE ACTION REQUIRED</p>
                            <p style='color: #78350f; margin: 10px 0 0 0;'>You must contact the Administration Office immediately to resolve this issue before you can enter the school premises.</p>
                        </div>
                        
                        <p style='color: #6b7280; font-size: 14px; margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 20px;'>
                            This is an automated message from the PCU RFID Security System. Please do not reply to this email.
                        </p>
                    </div>
                </div>
            ";
        } elseif ($severity === 'critical') {
            $subject = '⚠️ FINAL WARNING - Strike #3 Violation';
            $body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <div style='background: linear-gradient(135deg, #dc2626 0%, #ea580c 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                        <h1 style='margin: 0; font-size: 28px;'>⚠️ FINAL WARNING</h1>
                    </div>
                    <div style='background: #ffffff; padding: 30px; border: 2px solid #dc2626; border-top: none; border-radius: 0 0 10px 10px;'>
                        <p style='font-size: 16px; color: #1f2937; margin-bottom: 20px;'>Dear <strong>{$student['name']}</strong>,</p>
                        
                        <div style='background: #fee2e2; border-left: 4px solid #dc2626; padding: 15px; margin: 20px 0;'>
                            <p style='color: #991b1b; font-size: 18px; font-weight: bold; margin: 0;'>🚨 STRIKE #3 - FINAL WARNING</p>
                        </div>
                        
                        <p style='color: #374151; line-height: 1.6;'>You have been recorded entering school without your physical student ID card.</p>
                        
                        <div style='background: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                            <table style='width: 100%; border-collapse: collapse;'>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Student ID:</td>
                                    <td style='padding: 8px 0; color: #111827; font-weight: bold;'>{$student['student_id']}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Total Violations:</td>
                                    <td style='padding: 8px 0; color: #dc2626; font-weight: bold; font-size: 20px;'>{$violationCount} / 3</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Date & Time:</td>
                                    <td style='padding: 8px 0; color: #111827;'>{$timestamp}</td>
                                </tr>
                            </table>
                        </div>
                        
                        <div style='background: #fef3c7; border: 2px solid #f59e0b; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                            <p style='color: #92400e; margin: 0; font-weight: bold;'>⚠️ CRITICAL WARNING</p>
                            <p style='color: #78350f; margin: 10px 0 0 0;'><strong>One more violation will result in DENIED ENTRY to the school.</strong> You will not be able to enter the premises until you resolve this with the Administration Office.</p>
                        </div>
                        
                        <p style='color: #374151; line-height: 1.6;'>Please ensure you bring your physical student ID card every day to avoid being locked out.</p>
                        
                        <p style='color: #6b7280; font-size: 14px; margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 20px;'>
                            This is an automated message from the PCU RFID Security System. Please do not reply to this email.
                        </p>
                    </div>
                </div>
            ";
        } elseif ($severity === 'warning') {
            $subject = '⚡ Violation Warning - Strike #2';
            $body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <div style='background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                        <h1 style='margin: 0; font-size: 28px;'>⚡ Violation Warning</h1>
                    </div>
                    <div style='background: #ffffff; padding: 30px; border: 2px solid #f59e0b; border-top: none; border-radius: 0 0 10px 10px;'>
                        <p style='font-size: 16px; color: #1f2937; margin-bottom: 20px;'>Dear <strong>{$student['name']}</strong>,</p>
                        
                        <div style='background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0;'>
                            <p style='color: #92400e; font-size: 18px; font-weight: bold; margin: 0;'>⚡ Strike #2 Violation Recorded</p>
                        </div>
                        
                        <p style='color: #374151; line-height: 1.6;'>You have been recorded entering school without your physical student ID card.</p>
                        
                        <div style='background: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                            <table style='width: 100%; border-collapse: collapse;'>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Student ID:</td>
                                    <td style='padding: 8px 0; color: #111827; font-weight: bold;'>{$student['student_id']}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Total Violations:</td>
                                    <td style='padding: 8px 0; color: #f59e0b; font-weight: bold; font-size: 20px;'>{$violationCount} / 3</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Date & Time:</td>
                                    <td style='padding: 8px 0; color: #111827;'>{$timestamp}</td>
                                </tr>
                            </table>
                        </div>
                        
                        <div style='background: #fef3c7; border: 1px solid #fbbf24; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                            <p style='color: #92400e; margin: 0;'>⚠️ You have <strong>1 more chance</strong> before entry restrictions are applied. Please bring your physical ID card to avoid further violations.</p>
                        </div>
                        
                        <p style='color: #6b7280; font-size: 14px; margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 20px;'>
                            This is an automated message from the PCU RFID Security System. Please do not reply to this email.
                        </p>
                    </div>
                </div>
            ";
        } else {
            $subject = 'ℹ️ First Violation Notice - Reminder';
            $body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <div style='background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                        <h1 style='margin: 0; font-size: 28px;'>ℹ️ Violation Notice</h1>
                    </div>
                    <div style='background: #ffffff; padding: 30px; border: 2px solid #3b82f6; border-top: none; border-radius: 0 0 10px 10px;'>
                        <p style='font-size: 16px; color: #1f2937; margin-bottom: 20px;'>Dear <strong>{$student['name']}</strong>,</p>
                        
                        <div style='background: #dbeafe; border-left: 4px solid #3b82f6; padding: 15px; margin: 20px 0;'>
                            <p style='color: #1e40af; font-size: 18px; font-weight: bold; margin: 0;'>✓ First Violation Recorded</p>
                        </div>
                        
                        <p style='color: #374151; line-height: 1.6;'>You have been recorded entering school without your physical student ID card.</p>
                        
                        <div style='background: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                            <table style='width: 100%; border-collapse: collapse;'>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Student ID:</td>
                                    <td style='padding: 8px 0; color: #111827; font-weight: bold;'>{$student['student_id']}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Total Violations:</td>
                                    <td style='padding: 8px 0; color: #3b82f6; font-weight: bold; font-size: 20px;'>{$violationCount} / 3</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; color: #6b7280;'>Date & Time:</td>
                                    <td style='padding: 8px 0; color: #111827;'>{$timestamp}</td>
                                </tr>
                            </table>
                        </div>
                        
                        <div style='background: #e0e7ff; border: 1px solid #818cf8; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                            <p style='color: #3730a3; margin: 0;'>ℹ️ This is a friendly reminder to always bring your physical student ID card. After 3 violations, entry to the school will be denied.</p>
                        </div>
                        
                        <p style='color: #6b7280; font-size: 14px; margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 20px;'>
                            This is an automated message from the PCU RFID Security System. Please do not reply to this email.
                        </p>
                    </div>
                </div>
            ";
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);
        
        // Send email
        $mail->send();
        error_log("Violation email sent to {$student['email']} - Strike #{$violationCount} ({$severity})");
        
    } catch (\Exception $e) {
        error_log("Failed to send violation email: {$mail->ErrorInfo}");
    }
}
