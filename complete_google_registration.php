<?php
require_once 'db.php';

// Security: If user is already logged in, redirect to homepage
if (isset($_SESSION['user']) && !empty($_SESSION['user']['id'])) {
    header('Location: homepage.php');
    exit;
}

// Security: Check if Google registration data exists in session
// This page should ONLY be accessible during the Google Sign-In registration flow
if (!isset($_SESSION['google_registration'])) {
    // No registration data - redirect to login page
    header('Location: login.php?error=' . urlencode('Invalid registration session'));
    exit;
}

$google_data = $_SESSION['google_registration'];
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    $student_id = trim($_POST['student_id'] ?? '');
    
    // Validation
    if (empty($student_id)) {
        $error = 'Student ID is required';
    } elseif (strlen($student_id) < 3) {
        $error = 'Student ID must be at least 3 characters';
    } else {
        try {
            $pdo = pdo();
            
            // Check if student_id already exists
            $checkStmt = $pdo->prepare('SELECT id FROM users WHERE student_id = ?');
            $checkStmt->execute([$student_id]);
            
            if ($checkStmt->fetch()) {
                $error = 'This Student ID is already registered. Please use a different one.';
            } else {
                // Parse name into parts (Google gives full name)
                $name_parts = explode(' ', $google_data['name']);
                $first_name = $name_parts[0] ?? '';
                $middle_name = isset($name_parts[2]) ? $name_parts[1] : '';
                $last_name = isset($name_parts[2]) ? $name_parts[2] : ($name_parts[1] ?? '');
                
                // Create new user account
                // Password is set to a random hash since they'll use Google Sign-In
                $random_password = bin2hex(random_bytes(32));
                $hashed_password = password_hash($random_password, PASSWORD_DEFAULT);
                
                $insertStmt = $pdo->prepare('
                    INSERT INTO users (student_id, name, email, password, google_id, role, status, verification_status, created_at) 
                    VALUES (?, ?, ?, ?, ?, "Student", "Pending", "pending", NOW())
                ');
                
                $insertStmt->execute([
                    $student_id,
                    $google_data['name'],
                    $google_data['email'],
                    $hashed_password,
                    $google_data['google_id']
                ]);
                
                $new_user_id = $pdo->lastInsertId();
                
                // Send registration pending email
                require_once 'db.php';
                $subject = 'PCU RFID System - Registration Submitted';
                $body = '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                        <div style="background-color: #0056b3; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="color: white; margin: 0; font-size: 28px;">PCU RFID System</h1>
                        </div>
                        <div style="background-color: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px;">
                            <h2 style="color: #0056b3; margin-top: 0;">Registration Received!</h2>
                            <p>Hello ' . htmlspecialchars($google_data['name']) . ',</p>
                            <p>Thank you for registering with the PCU RFID System using Google Sign-In. Your account has been successfully created and is now awaiting verification by the Student Services Office.</p>
                            
                            <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                                <p style="margin: 0; color: #856404;"><strong>⏳ Verification Required</strong></p>
                                <p style="margin: 10px 0 0 0; color: #856404;">Your credentials will be verified by our administrators. You will receive an email notification once your account is approved.</p>
                            </div>
                            
                            <h3 style="color: #333; margin-top: 25px;">Account Details:</h3>
                            <ul style="color: #555; line-height: 1.8;">
                                <li><strong>Student ID:</strong> ' . htmlspecialchars($student_id) . '</li>
                                <li><strong>Name:</strong> ' . htmlspecialchars($google_data['name']) . '</li>
                                <li><strong>Email:</strong> ' . htmlspecialchars($google_data['email']) . '</li>
                                <li><strong>Sign-In Method:</strong> Google Sign-In</li>
                            </ul>
                            
                            <p style="color: #6c757d; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6;">
                                <strong>Note:</strong> You will not be able to log in until your account has been verified. This typically takes 1-2 business days.
                            </p>
                        </div>
                    </div>';
                sendMail($google_data['email'], $subject, $body);
                
                // Clear Google registration data from session
                unset($_SESSION['google_registration']);
                
                // Redirect to login with info message
                $_SESSION['info'] = 'Registration submitted successfully! Your account is pending verification by the Student Services Office.';
                header('Location: login.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $error = 'Database error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Registration | PCU RFID</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style type="text/tailwindcss">
        .bg-pcu {
            position: relative;
        }
        .bg-pcu::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url('pcu-building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            filter: blur(2px);
            -webkit-filter: blur(2px);
            z-index: -1;
        }
    </style>
</head>
<body class="text-slate-800 bg-pcu min-h-screen">
    <div class="min-h-screen flex items-center justify-center p-4 relative z-10">
        <div class="max-w-md w-full bg-white/90 shadow-2xl rounded-2xl p-8 transition-all fade-in">
            <!-- Logo/Header -->
            <div class="mb-8 text-center">
                <a href="login.php" class="inline-block hover:opacity-80 transition-opacity">
                    <img src="pcu-logo.png" alt="PCU Logo" class="w-24 h-24 mx-auto mb-6">
                </a>
                <h1 class="text-3xl font-semibold text-sky-700 mb-2">Complete Your Registration</h1>
                <p class="text-base text-slate-600">Just one more step to get started!</p>
            </div>

            <!-- Google Account Info -->
            <div class="bg-sky-50 border border-sky-200 rounded-lg p-4 mb-6">
                <div class="flex items-start">
                    <?php if (!empty($google_data['picture'])): ?>
                        <img src="<?php echo htmlspecialchars($google_data['picture']); ?>" 
                             alt="Profile" 
                             class="w-12 h-12 rounded-full mr-3">
                    <?php else: ?>
                        <div class="w-12 h-12 rounded-full bg-sky-600 flex items-center justify-center text-white font-bold text-lg mr-3">
                            <?php echo strtoupper(substr($google_data['name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div class="flex-1">
                        <p class="font-semibold text-slate-800"><?php echo htmlspecialchars($google_data['name']); ?></p>
                        <p class="text-sm text-slate-600"><?php echo htmlspecialchars($google_data['email']); ?></p>
                        <p class="text-xs text-green-600 mt-1 flex items-center">
                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            Verified by Google
                        </p>
                    </div>
                </div>
            </div>

            <!-- Information Box -->
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
                <div class="flex items-start">
                    <svg class="w-5 h-5 text-amber-600 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                    </svg>
                    <div class="text-sm text-amber-800">
                        <p class="font-semibold mb-1">About your Student ID</p>
                        <p>Please enter your official PCU Student ID. This will be used to identify you in the system and link your Google account to your student record.</p>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="mb-6 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-4 shadow-sm flex items-center">
                    <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- Registration Form -->
            <form method="POST" action="" class="space-y-6">
                <?php echo csrf_input(); ?>
                
                <div>
                    <label for="student_id" class="block text-sm font-semibold text-slate-700 mb-2">
                        Student ID <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="student_id" 
                        name="student_id" 
                        required
                        placeholder="Enter your PCU Student ID"
                        value="<?php echo htmlspecialchars($_POST['student_id'] ?? ''); ?>"
                        class="w-full h-11 px-4 text-sm border-2 border-slate-300 rounded-lg
                               focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-200
                               transition duration-150"
                        autocomplete="off">
                    <p class="mt-2 text-xs text-slate-500">Example: 2024-12345, STU-12345, or your assigned ID format</p>
                </div>

                <button 
                    type="submit"
                    class="w-full h-11 bg-sky-600 text-white text-base font-semibold rounded-lg
                           hover:bg-sky-700 active:bg-sky-800
                           transform transition duration-150
                           hover:shadow-lg active:scale-[0.98]
                           focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2">
                    Complete Registration
                </button>
            </form>

            <!-- Cancel Link -->
            <div class="mt-6 text-center">
                <a href="login.php" class="text-sm text-slate-600 hover:text-slate-800 underline">
                    Cancel and return to login
                </a>
            </div>
        </div>
    </div>
</body>
</html>
