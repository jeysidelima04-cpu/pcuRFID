<?php
require_once 'db.php';
require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Enhanced session security check
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id']) || empty($_SESSION['user']['email'])) {
    session_unset();
    session_destroy();
    header('Location: login.php?error=' . urlencode('Please log in to access the digital ID'));
    exit;
}

// Session timeout after 30 minutes of inactivity
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    header('Location: login.php?error=' . urlencode('Session expired. Please log in again'));
    exit;
}

$_SESSION['last_activity'] = time();

// Get user information from database
try {
    $pdo = pdo();
    $stmt = $pdo->prepare('SELECT id, student_id, name, email, profile_picture, created_at, status FROM users WHERE id = ? AND email = ? LIMIT 1');
    $stmt->execute([$_SESSION['user']['id'], $_SESSION['user']['email']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        session_unset();
        session_destroy();
        header('Location: login.php?error=' . urlencode('User not found'));
        exit;
    }
    
    // Create secure JWT token for QR code
    $jwt_secret = env('JWT_SECRET', 'pcurfid2-default-secret-change-in-production');
    $payload = [
        'student_id' => $user['student_id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'issued_at' => time(),
        'expires_at' => time() + 300
    ];
    $jwt_token = JWT::encode($payload, $jwt_secret, 'HS256');
    
    // Generate hash for checking if QR was used
    $token_hash = hash('sha256', $jwt_token);
    
    // Get initials for avatar fallback
    $nameParts = explode(' ', $user['name']);
    $initials = strtoupper(substr($nameParts[0], 0, 1));
    if (count($nameParts) > 1) {
        $initials .= strtoupper(substr(end($nameParts), 0, 1));
    }
    
} catch (\Exception $e) {
    error_log('Digital ID error: ' . $e->getMessage());
    header('Location: homepage.php?error=' . urlencode('Unable to generate digital ID'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0c4a6e">
    <title>Digital ID | GateWatch</title>
    <link rel="icon" type="image/png" href="assets/images/gatewatch-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
            padding-top: 80px;
            position: relative;
            overflow-x: hidden;
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        /* Blurred Background - Same as homepage */
        body::before {
            content: '';
            position: fixed;
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
            z-index: -2;
            opacity: 0.9;
        }
        
        /* Blue overlay for brand consistency */
        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: -1;
            background: linear-gradient(135deg, rgba(12, 74, 110, 0.7) 0%, rgba(3, 105, 161, 0.6) 50%, rgba(2, 132, 199, 0.5) 100%);
        }
        
        /* Header */
        .header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 16px;
        }
        
        .header-inner {
            max-width: 400px;
            margin: 0 auto;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 12px 16px;
            display: flex;
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .back-btn {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #475569;
            transition: all 0.2s;
        }
        
        .back-btn:hover {
            background: #e0f2fe;
            color: #0284c7;
        }
        
        .logo {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 8px;
        }
        
        .logo img {
            width: 32px;
            height: 32px;
        }
        
        .logo span {
            font-weight: 700;
            color: #0369a1;
            font-size: 18px;
        }
        
        .refresh-btn {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #e0f2fe;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0284c7;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .refresh-btn:hover {
            background: #bae6fd;
        }
        
        /* Card */
        .card {
            width: 100%;
            max-width: 380px;
            background: rgba(255,255,255,0.98);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
        }
        
        /* Card Header */
        .card-header {
            background: linear-gradient(135deg, #0369a1 0%, #0284c7 100%);
            padding: 24px 24px 40px 24px;
            text-align: center;
            position: relative;
        }
        
        .university-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 6px 16px;
            border-radius: 20px;
            margin-bottom: 8px;
        }
        
        .university-badge span {
            color: rgba(255,255,255,0.9);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        
        .card-title {
            color: white;
            font-size: 22px;
            font-weight: 700;
        }
        
        .verified-badge {
            position: absolute;
            bottom: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: #22c55e;
            padding: 8px 20px;
            border-radius: 20px;
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 12px rgba(34,197,94,0.4);
        }
        
        .verified-badge .dot {
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
        }
        
        .verified-badge span {
            color: white;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        /* Profile Section */
        .profile-section {
            padding: 32px 24px 24px 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .avatar-wrapper {
            width: 108px;
            height: 108px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0ea5e9, #0369a1);
            padding: 4px;
            margin-bottom: 16px;
        }
        
        .avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: linear-gradient(135deg, #38bdf8, #0284c7);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 4px solid white;
        }
        
        .avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .avatar-initials {
            color: white;
            font-size: 36px;
            font-weight: 700;
        }
        
        .student-name {
            font-size: 22px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
            text-align: center;
        }
        
        .student-id {
            font-size: 18px;
            font-weight: 700;
            color: #0284c7;
            letter-spacing: 0.5px;
        }
        
        /* Info Cards */
        .info-cards {
            width: 100%;
            padding: 0 24px 24px 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .info-card {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 14px;
        }
        
        .info-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .info-icon.blue { background: #e0f2fe; color: #0284c7; }
        .info-icon.green { background: #dcfce7; color: #16a34a; }
        
        .info-content {
            flex: 1;
            min-width: 0;
        }
        
        .info-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
        }
        
        .info-value {
            font-size: 14px;
            color: #334155;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* QR Section */
        .qr-section {
            margin: 0 24px 24px 24px;
            background: linear-gradient(145deg, #f8fafc, #f1f5f9);
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .qr-header {
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 16px;
        }
        
        .qr-header svg {
            color: #0284c7;
        }
        
        .qr-header span {
            font-size: 14px;
            font-weight: 600;
            color: #334155;
        }
        
        /* QR CODE CENTERING - THE FIX */
        .qr-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .qr-wrapper {
            background: white;
            padding: 16px;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            display: inline-flex;
            justify-content: center;
            align-items: center;
        }
        
        #qrcode {
            display: block;
        }
        
        /* Verified Overlay */
        .qr-verified-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(34, 197, 94, 0.95);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            animation: fadeIn 0.3s ease;
        }
        
        .qr-verified-overlay svg {
            width: 60px;
            height: 60px;
            color: white;
            margin-bottom: 8px;
        }
        
        .qr-verified-overlay span {
            color: white;
            font-weight: 700;
            font-size: 16px;
        }
        
        .qr-wrapper {
            position: relative;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        
        /* Timer */
        .timer {
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }
        
        .timer-ring {
            width: 40px;
            height: 40px;
        }
        
        .timer-ring svg {
            transform: rotate(-90deg);
        }
        
        .timer-text .label {
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
        }
        
        .timer-text .time {
            font-size: 18px;
            color: #1e293b;
            font-weight: 700;
        }
        
        /* Print */
        @media print {
            body { background: white; padding: 0; }
            .header, .buttons, .footer { display: none; }
            .card { box-shadow: none; max-width: 100%; }
        }
        
        /* Responsive */
        @media (max-width: 400px) {
            body { padding: 12px; padding-top: 76px; }
            .card { border-radius: 20px; }
            .card-header { padding: 20px 20px 36px 20px; }
            .profile-section { padding: 28px 20px 20px 20px; }
            .info-cards { padding: 0 20px 20px 20px; }
            .qr-section { margin: 0 20px 20px 20px; padding: 16px; }
            .qr-wrapper { padding: 12px; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-inner">
            <a href="homepage.php" class="back-btn">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div class="logo">
                <img src="pcu-logo.png" alt="PCU" onerror="this.style.display='none'">
                <span>GateWatch</span>
            </div>
            <button class="refresh-btn" onclick="location.reload()">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
            </button>
        </div>
    </header>

    <!-- Card -->
    <div class="card">
        <!-- Header -->
        <div class="card-header">
            <div class="university-badge">
                <span>Philippine Christian University</span>
            </div>
            <h1 class="card-title">Student Digital ID</h1>
            <?php if (isset($user['status']) && $user['status'] === 'Active'): ?>
            <div class="verified-badge">
                <div class="dot"></div>
                <span>VERIFIED</span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Profile -->
        <div class="profile-section">
            <div class="avatar-wrapper">
                <div class="avatar">
                    <?php if (!empty($user['profile_picture'])): ?>
                        <img src="assets/images/profiles/<?php echo e($user['profile_picture']); ?>" alt="<?php echo e($user['name']); ?>" onerror="this.parentElement.innerHTML='<span class=\'avatar-initials\'><?php echo $initials; ?></span>'">
                    <?php else: ?>
                        <span class="avatar-initials"><?php echo $initials; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <h2 class="student-name"><?php echo e($user['name']); ?></h2>
            <p class="student-id"><?php echo e($user['student_id']); ?></p>
        </div>
        
        <!-- Info -->
        <div class="info-cards">
            <div class="info-card">
                <div class="info-icon blue">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div class="info-content">
                    <p class="info-label">Email Address</p>
                    <p class="info-value"><?php echo e($user['email']); ?></p>
                </div>
            </div>
            <div class="info-card">
                <div class="info-icon green">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div class="info-content">
                    <p class="info-label">Member Since</p>
                    <p class="info-value"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
                </div>
            </div>
        </div>
        
        <!-- QR Code -->
        <div class="qr-section">
            <div class="qr-header">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/>
                </svg>
                <span>Scan for Verification</span>
            </div>
            
            <div class="qr-container">
                <div class="qr-wrapper">
                    <canvas id="qrcode"></canvas>
                </div>
            </div>
            
            <div class="timer">
                <div class="timer-ring">
                    <svg viewBox="0 0 36 36">
                        <path stroke="#e2e8f0" stroke-width="3" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                        <path id="timerProgress" stroke="#0284c7" stroke-width="3" fill="none" stroke-linecap="round" stroke-dasharray="100, 100" stroke-dashoffset="0" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                    </svg>
                </div>
                <div class="timer-text">
                    <p class="label">Expires in</p>
                    <p class="time" id="countdown">5:00</p>
                </div>
            </div>
        </div>
        
    </div>

    <script src="assets/js/qrious.min.js"></script>
    <script>
        // QR Code Generation
        new QRious({
            element: document.getElementById('qrcode'),
            value: "<?php echo $jwt_token; ?>",
            size: 200,
            level: 'H',
            background: '#ffffff',
            foreground: '#0f172a'
        });
        
        // Timer
        let remaining = 300;
        const countdownEl = document.getElementById('countdown');
        const progressEl = document.getElementById('timerProgress');
        const tokenHash = "<?php echo $token_hash; ?>";
        let qrVerified = false;
        
        // Check if QR was scanned (poll every 2 seconds)
        function checkQRStatus() {
            if (qrVerified) return;
            
            fetch('check_qr_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token_hash: tokenHash })
            })
            .then(res => res.json())
            .then(data => {
                if (data.used) {
                    qrVerified = true;
                    showVerifiedOverlay(data.verified_by);
                    // Auto refresh after 3 seconds
                    setTimeout(() => {
                        location.reload();
                    }, 3000);
                }
            })
            .catch(err => console.log('Status check error:', err));
        }
        
        // Show verified overlay on QR
        function showVerifiedOverlay(verifiedBy) {
            const wrapper = document.querySelector('.qr-wrapper');
            const overlay = document.createElement('div');
            overlay.className = 'qr-verified-overlay';
            overlay.innerHTML = `
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>VERIFIED!</span>
                <span style="font-size:12px;font-weight:500;margin-top:4px;">by ${verifiedBy || 'Security'}</span>
            `;
            wrapper.appendChild(overlay);
            
            // Update timer text
            countdownEl.textContent = 'VERIFIED';
            countdownEl.style.color = '#22c55e';
            progressEl.style.stroke = '#22c55e';
        }
        
        // Start polling for QR status
        setInterval(checkQRStatus, 2000);
        
        // Timer countdown
        setInterval(() => {
            if (qrVerified) return;
            remaining--;
            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            countdownEl.textContent = mins + ':' + secs.toString().padStart(2, '0');
            
            const progress = (remaining / 300) * 100;
            progressEl.style.strokeDashoffset = (100 - progress);
            
            if (remaining <= 60) {
                progressEl.style.stroke = '#ef4444';
                countdownEl.style.color = '#ef4444';
            } else if (remaining <= 120) {
                progressEl.style.stroke = '#f59e0b';
            }
            
            if (remaining <= 0) {
                countdownEl.textContent = 'EXPIRED';
                if (confirm('QR Code expired. Refresh?')) location.reload();
            }
        }, 1000);
        
        // Initial check
        checkQRStatus();
    </script>
</body>
</html>
