<?php
/**
 * Admin endpoint to approve or deny student account verification
 */

require_once __DIR__ . '/../db.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF protection for JSON API
$headers = getallheaders();
if (!isset($headers['X-CSRF-Token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $headers['X-CSRF-Token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['student_id']) || !isset($data['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$student_id = (int)$data['student_id'];
$action = $data['action']; // 'approve' or 'deny'
$admin_id = (int)$_SESSION['admin_id'];

if (!in_array($action, ['approve', 'deny'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

try {
    $pdo = pdo();
    
    // Get student information
    $stmt = $pdo->prepare('SELECT id, student_id, name, email, verification_status FROM users WHERE id = ? AND role = "Student" LIMIT 1');
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit;
    }
    
    if ($student['verification_status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Account already processed']);
        exit;
    }
    
    if ($action === 'approve') {
        // Approve the account
        $stmt = $pdo->prepare('
            UPDATE users 
            SET verification_status = "approved", 
                status = "Active",
                verified_at = NOW(),
                verified_by = ?
            WHERE id = ?
        ');
        $stmt->execute([$admin_id, $student_id]);
        
        // Send approval email
        $subject = 'PCU RFID System - Account Approved! ✅';
        $body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                <div style="background-color: #28a745; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                    <h1 style="color: white; margin: 0; font-size: 28px;">✅ Account Approved!</h1>
                </div>
                <div style="background-color: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px;">
                    <h2 style="color: #28a745; margin-top: 0;">Welcome to PCU RFID System!</h2>
                    <p>Hello ' . htmlspecialchars($student['name']) . ',</p>
                    <p>Great news! Your account has been verified and approved by the Student Services Office. You can now log in and start using the PCU RFID System.</p>
                    
                    <div style="background-color: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin: 0; color: #155724;"><strong>✓ Your Account is Active</strong></p>
                        <p style="margin: 10px 0 0 0; color: #155724;">You can now log in using your Student ID and password, or continue using Google Sign-In.</p>
                    </div>
                    
                    <div style="text-align: center; margin: 30px 0;">
                        <a href="http://localhost/pcuRFID2/login.php" 
                           style="display: inline-block; background-color: #0056b3; color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px;">
                            Log In Now
                        </a>
                    </div>
                    
                    <h3 style="color: #333; margin-top: 25px;">Your Account Details:</h3>
                    <ul style="color: #555; line-height: 1.8;">
                        <li><strong>Student ID:</strong> ' . htmlspecialchars($student['student_id']) . '</li>
                        <li><strong>Name:</strong> ' . htmlspecialchars($student['name']) . '</li>
                        <li><strong>Email:</strong> ' . htmlspecialchars($student['email']) . '</li>
                        <li><strong>Status:</strong> <span style="color: #28a745;">Active & Verified</span></li>
                    </ul>
                    
                    <p style="color: #6c757d; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6;">
                        <strong>Next Steps:</strong><br>
                        1. Log in to your account<br>
                        2. Complete your profile if needed<br>
                        3. Register your RFID card at the Student Services Office
                    </p>
                    
                    <p style="color: #6c757d; font-size: 12px; text-align: center; margin-top: 30px;">
                        This is an automated message from the PCU RFID System.<br>
                        If you have any questions, please contact the Student Services Office.
                    </p>
                </div>
            </div>';
        
        sendMail($student['email'], $subject, $body);
        
        echo json_encode([
            'success' => true,
            'message' => 'Account approved successfully',
            'student_name' => $student['name']
        ]);
        
    } else if ($action === 'deny') {
        // Deny and delete the account
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$student_id]);
        
        // Send denial email
        $subject = 'PCU RFID System - Account Verification Denied';
        $body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                <div style="background-color: #dc3545; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                    <h1 style="color: white; margin: 0; font-size: 28px;">Account Verification Update</h1>
                </div>
                <div style="background-color: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px;">
                    <h2 style="color: #dc3545; margin-top: 0;">Verification Not Approved</h2>
                    <p>Hello ' . htmlspecialchars($student['name']) . ',</p>
                    <p>We regret to inform you that your PCU RFID System account registration could not be verified at this time.</p>
                    
                    <div style="background-color: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin: 0; color: #721c24;"><strong>Verification Unsuccessful</strong></p>
                        <p style="margin: 10px 0 0 0; color: #721c24;">Your submitted credentials could not be verified against our enrolled student records.</p>
                    </div>
                    
                    <h3 style="color: #333; margin-top: 25px;">Possible Reasons:</h3>
                    <ul style="color: #555; line-height: 1.8;">
                        <li>Student ID does not match our records</li>
                        <li>Name spelling differs from official enrollment records</li>
                        <li>Email address is not associated with your student account</li>
                        <li>You are not currently enrolled at PCU</li>
                    </ul>
                    
                    <h3 style="color: #333; margin-top: 25px;">What to Do Next:</h3>
                    <p style="color: #555;">If you believe this is an error, please:</p>
                    <ol style="color: #555; line-height: 1.8;">
                        <li>Visit the Student Services Office in person</li>
                        <li>Bring your valid student ID and enrollment documents</li>
                        <li>Request assistance with account verification</li>
                        <li>Re-register with the correct information provided by the office</li>
                    </ol>
                    
                    <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin: 0; color: #856404;"><strong>Account Removed</strong></p>
                        <p style="margin: 10px 0 0 0; color: #856404;">Your account has been removed from our system. You may create a new account with verified information.</p>
                    </div>
                    
                    <p style="color: #6c757d; font-size: 12px; text-align: center; margin-top: 30px;">
                        PCU Student Services Office<br>
                        Email: studentservices@pcu.edu.ph<br>
                        This is an automated message from the PCU RFID System.
                    </p>
                </div>
            </div>';
        
        sendMail($student['email'], $subject, $body);
        
        echo json_encode([
            'success' => true,
            'message' => 'Account denied and removed successfully',
            'student_name' => $student['name']
        ]);
    }
    
} catch (PDOException $e) {
    error_log('Verification error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
