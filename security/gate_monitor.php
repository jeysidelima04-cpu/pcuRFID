<?php
require_once __DIR__ . '/../db.php';

// Check if security guard is logged in
if (!isset($_SESSION['security_logged_in'])) {
    header('Location: security_login.php');
    exit;
}

$guard_username = $_SESSION['security_username'] ?? 'Security Guard';

// Get today's statistics
try {
    $pdo = pdo();
    
    // Total scans today
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM violations 
        WHERE DATE(scanned_at) = CURDATE()
    ");
    $todayScans = $stmt->fetchColumn();
    
    // Unique students today
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT user_id) 
        FROM violations 
        WHERE DATE(scanned_at) = CURDATE()
    ");
    $uniqueStudents = $stmt->fetchColumn();
    
    // High violation students (3+)
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE role = 'Student' AND violation_count >= 3
    ");
    $highViolationCount = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log('Stats error: ' . $e->getMessage());
    $todayScans = 0;
    $uniqueStudents = 0;
    $highViolationCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PCU RFID Security | Gate Monitor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style type="text/tailwindcss">
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .btn-hover {
            transition: all 0.3s ease;
        }
        .btn-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 86, 179, 0.1), 0 2px 4px -1px rgba(0, 86, 179, 0.06);
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.95); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.7; }
            100% { transform: scale(0.95); opacity: 1; }
        }
        .pulse-ring {
            animation: pulse-ring 2s ease-in-out infinite;
        }
    </style>
</head>
<body class="bg-slate-50">`
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-slate-200 sticky top-0 z-50">
        <div class="container mx-auto px-4 md:px-8 py-4">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-full p-2 flex items-center justify-center shadow-lg">
                        <svg class="w-6 h-6 md:w-8 md:h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-lg md:text-xl font-bold text-slate-800">Gate Monitor</h1>
                        <p class="text-slate-600 text-xs md:text-sm">Security System</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 md:gap-4">
                    <div class="text-right hidden sm:block">
                        <p class="text-slate-800 font-medium text-sm md:text-base"><?php echo htmlspecialchars($guard_username); ?></p>
                        <p class="text-slate-500 text-xs"><?php echo date('M j, Y g:i A'); ?></p>
                    </div>
                    <a href="security_logout.php" class="px-3 md:px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors text-sm font-medium btn-hover">
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 md:px-8 py-6 md:py-8">
        <!-- Page Title -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-800">Gate Entrance Monitoring</h1>
            <p class="text-slate-600 mt-1">Monitor student RFID card scans and track violations in real-time</p>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6 mb-6 md:mb-8">
            <!-- Today's Scans -->
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-medium opacity-90">Today's Scans</h3>
                    <svg class="w-8 h-8 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <p class="text-3xl font-bold mb-1"><?php echo number_format($todayScans); ?></p>
                <p class="text-sm opacity-75">Violations</p>
            </div>

            <!-- Unique Students -->
            <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-medium opacity-90">Unique Students</h3>
                    <svg class="w-8 h-8 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
                <p class="text-3xl font-bold mb-1"><?php echo number_format($uniqueStudents); ?></p>
                <p class="text-sm opacity-75">Today</p>
            </div>

            <!-- High Violations -->
            <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl shadow-lg p-6 text-white sm:col-span-2 lg:col-span-1">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-medium opacity-90">High Violations</h3>
                    <svg class="w-8 h-8 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <p class="text-3xl font-bold mb-1"><?php echo number_format($highViolationCount); ?></p>
                <p class="text-sm opacity-75">Students (3+ strikes)</p>
            </div>
        </div>

        <!-- Main Scan Area -->
        <div class="max-w-4xl mx-auto mb-6 md:mb-8">
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="bg-gradient-to-r from-slate-50 to-blue-50 px-6 py-4 border-b border-slate-200">
                    <h2 class="text-lg font-semibold text-slate-800">RFID Scanner</h2>
                    <p class="text-sm text-slate-600">Tap student card on reader to record entry</p>
                </div>
                <!-- Scan Status Display -->
                <div id="scanStatus" class="p-8 md:p-12 text-center min-h-[300px] md:min-h-[400px] flex items-center justify-center">
                    <div>
                        <div class="mb-6 relative inline-block">
                            <div class="pulse-ring absolute inset-0 bg-blue-400 rounded-full opacity-20"></div>
                            <svg class="w-24 h-24 md:w-32 md:h-32 text-slate-300 relative z-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                <circle cx="12" cy="12" r="3" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <h2 class="text-2xl md:text-3xl font-bold text-slate-700 mb-2">Ready to Scan</h2>
                        <p class="text-slate-500 text-sm md:text-base">Hold RFID card near scanner to verify student entry</p>
                        <p class="text-slate-400 text-xs md:text-sm mt-2">System Active • Waiting for card...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Scans Log -->
        <div class="max-w-5xl mx-auto">
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800">Recent Scans</h3>
                        <p class="text-sm text-slate-600">Live activity log</p>
                    </div>
                    <button onclick="clearLog()" class="text-sm text-[#0056b3] hover:text-blue-700 font-medium transition-colors">
                        Clear Log
                    </button>
                </div>
                <div id="scanLog" class="p-6 space-y-3 max-h-[400px] overflow-y-auto bg-slate-50">
                    <p class="text-center text-slate-400 py-8 text-sm">No scans recorded yet. Waiting for first card tap...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
    let cardBuffer = '';
    let bufferTimeout = null;
    let scanLog = [];

    // Listen for RFID scanner (keyboard emulation) - Use keydown to capture ALL keys before processing
    document.addEventListener('keydown', function(e) {
        // If Enter key, process the buffer we've accumulated
        if (e.key === 'Enter') {
            e.preventDefault();
            
            const uid = cardBuffer.trim();
            console.log('RFID Scan Complete - Buffer:', cardBuffer, 'Trimmed:', uid, 'Length:', uid.length);
            
            if (uid.length >= 4) {
                processRFIDScan(uid);
            } else {
                console.error('Invalid scan - too short:', uid);
            }
            cardBuffer = '';
            clearTimeout(bufferTimeout);
            return;
        }
        
        // Accumulate all other single-character keys
        if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            cardBuffer += e.key;
            console.log('Character captured:', e.key, 'Buffer now:', cardBuffer);
            clearTimeout(bufferTimeout);
            
            // Reset buffer after 500ms of no input (in case scan was interrupted)
            bufferTimeout = setTimeout(() => {
                if (cardBuffer.length > 0 && cardBuffer.length < 4) {
                    console.warn('Buffer timeout - incomplete scan, clearing:', cardBuffer);
                    cardBuffer = '';
                }
            }, 500);
        }
    });

    function processRFIDScan(uid) {
        // Show processing state
        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in">
                <div class="mb-6">
                    <svg class="animate-spin w-20 h-20 md:w-24 md:h-24 mx-auto text-blue-500" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
                <h2 class="text-2xl md:text-3xl font-bold text-blue-600 mb-2">Processing...</h2>
                <p class="text-slate-600 font-mono text-sm md:text-base">RFID: ${uid}</p>
            </div>
        `;

        // Send to backend
        fetch('gate_scan.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({rfid_uid: uid})
        })
        .then(res => res.json())
        .then(data => {
            console.log('Backend response:', data);
            
            // Log debug info if available
            if (data.debug) {
                console.log('=== DEBUG INFO ===');
                console.log('Scanned UID:', data.debug.scanned_uid);
                console.log('Scanned Length:', data.debug.scanned_length);
                console.log('Total Registered Cards:', data.debug.total_registered);
                console.log('Registered Cards:', data.debug.registered_cards);
            }
            
            if (data.success) {
                displaySuccessScan(data);
            } else if (data.access_denied) {
                displayAccessDenied(data);
            } else {
                displayErrorScan(data, uid);
            }
        })
        .catch(error => {
            console.error('Scan error:', error);
            displayErrorScan({error: 'Network error occurred'}, uid);
        });
    }

    function displaySuccessScan(data) {
        const student = data.student;
        let severityColor = 'yellow';
        let severityBg = 'bg-yellow-50';
        let severityBorder = 'border-yellow-400';
        let severityIcon = '⚠️';
        
        if (student.severity === 'critical') {
            severityColor = 'red';
            severityBg = 'bg-red-50';
            severityBorder = 'border-red-500';
            severityIcon = '⚠️';
        } else if (student.severity === 'high') {
            severityColor = 'orange';
            severityBg = 'bg-orange-50';
            severityBorder = 'border-orange-500';
            severityIcon = '⚠️';
        } else if (student.severity === 'medium') {
            severityColor = 'yellow';
            severityBg = 'bg-yellow-50';
            severityBorder = 'border-yellow-400';
            severityIcon = '⚡';
        } else {
            severityColor = 'green';
            severityBg = 'bg-green-50';
            severityBorder = 'border-green-400';
            severityIcon = '✓';
        }
        
        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in w-full">
                <div class="${severityBg} border-4 ${severityBorder} rounded-2xl p-6 md:p-8">
                    <div class="text-5xl md:text-6xl mb-4">${severityIcon}</div>
                    <h2 class="text-2xl md:text-3xl font-bold text-${severityColor}-800 mb-3">NO PHYSICAL ID</h2>
                    
                    ${student.profile_picture ? `
                        <div class="flex justify-center mb-4">
                            <img src="../assets/images/profiles/${student.profile_picture}" 
                                 alt="${student.name}" 
                                 class="w-24 h-24 md:w-32 md:h-32 rounded-full object-cover border-4 border-white shadow-lg">
                        </div>
                    ` : ''}
                    
                    <div class="bg-white rounded-lg p-4 md:p-6 mb-4">
                        <p class="text-xl md:text-2xl font-bold text-slate-800 mb-2">${student.name}</p>
                        <p class="text-slate-600 mb-1">ID: ${student.student_id}</p>
                        <p class="text-slate-500 text-sm">${student.email}</p>
                    </div>
                    <div class="bg-${severityColor}-100 rounded-lg p-4 mb-3">
                        <p class="text-${severityColor}-900 font-bold text-2xl md:text-3xl mb-1">Violation #${student.violation_count}</p>
                        <p class="text-${severityColor}-700 text-sm md:text-base">${student.severity_message}</p>
                    </div>
                    <p class="text-slate-600 text-sm md:text-base">${data.message}</p>
                    ${student.violation_count === 3 ? '<p class="text-red-600 font-bold mt-3 text-sm md:text-base animate-pulse">🚨 FINAL WARNING - Next time entry will be DENIED!</p>' : ''}
                </div>
            </div>
        `;

        // Add to log
        addToScanLog(student, data.timestamp);

        // Reset after delay - optimized for fast queue processing
        setTimeout(resetScanDisplay, student.violation_count === 3 ? 3000 : 3000);
    }

    function displayErrorScan(data, uid) {
        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in">
                <div class="bg-red-50 border-4 border-red-500 rounded-2xl p-6 md:p-8">
                    <div class="text-5xl md:text-6xl mb-4">❌</div>
                    <h2 class="text-2xl md:text-3xl font-bold text-red-800 mb-3">UNKNOWN CARD</h2>
                    <p class="text-slate-600 mb-2">This RFID card is not registered</p>
                    <code class="bg-slate-100 px-3 py-2 rounded text-sm md:text-base inline-block mb-3">${uid}</code>
                    <p class="text-red-600 text-sm md:text-base">${data.error || 'Access Denied'}</p>
                </div>
            </div>
        `;

        setTimeout(resetScanDisplay, 1500);
    }

    function displayAccessDenied(data) {
        const student = data.student;
        
        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in w-full">
                <div class="bg-red-50 border-4 border-red-600 rounded-2xl p-6 md:p-8">
                    <div class="text-6xl md:text-7xl mb-4">⛔</div>
                    <h2 class="text-3xl md:text-4xl font-bold text-red-900 mb-4 animate-pulse">ACCESS DENIED</h2>
                    
                    ${student.profile_picture ? `
                        <div class="flex justify-center mb-4">
                            <img src="../assets/images/profiles/${student.profile_picture}" 
                                 alt="${student.name}" 
                                 class="w-24 h-24 md:w-32 md:h-32 rounded-full object-cover border-4 border-red-500 shadow-lg">
                        </div>
                    ` : ''}
                    
                    <div class="bg-white rounded-lg p-4 md:p-6 mb-4 border-2 border-red-300">
                        <p class="text-xl md:text-2xl font-bold text-slate-800 mb-2">${student.name}</p>
                        <p class="text-slate-600 mb-1">ID: ${student.student_id}</p>
                        <p class="text-slate-500 text-sm">${student.email}</p>
                    </div>
                    <div class="bg-red-600 rounded-lg p-4 md:p-6 mb-4 text-white">
                        <p class="font-bold text-2xl md:text-3xl mb-2">🚫 ENTRY NOT ALLOWED</p>
                        <p class="text-lg md:text-xl mb-2">Total Violations: ${student.violation_count}</p>
                        <p class="text-sm md:text-base">Maximum limit of 3 strikes has been exceeded</p>
                    </div>
                    <div class="bg-yellow-100 border-2 border-yellow-500 rounded-lg p-4 mb-3">
                        <p class="text-yellow-900 font-bold text-base md:text-lg mb-1">⚠️ ACTION REQUIRED</p>
                        <p class="text-yellow-800 text-sm md:text-base">${data.message}</p>
                    </div>
                    <p class="text-red-700 font-bold text-sm md:text-base mt-4 animate-pulse">🔒 STUDENT MUST CONTACT ADMINISTRATION OFFICE</p>
                </div>
            </div>
        `;

        // Faster reset for denied access to keep line moving
        setTimeout(resetScanDisplay, 3000);
    }

    function resetScanDisplay() {
        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in">
                <div class="mb-6 relative inline-block">
                    <div class="pulse-ring absolute inset-0 bg-blue-400 rounded-full opacity-20"></div>
                    <svg class="w-24 h-24 md:w-32 md:h-32 text-slate-300 relative z-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        <circle cx="12" cy="12" r="3" stroke-width="1.5"/>
                    </svg>
                </div>
                <h2 class="text-2xl md:text-3xl font-bold text-slate-700 mb-2">Ready to Scan</h2>
                <p class="text-slate-500 text-sm md:text-base">Hold RFID card near scanner</p>
            </div>
        `;
    }

    function addToScanLog(student, timestamp) {
        const time = new Date(timestamp).toLocaleTimeString();
        
        let badgeColor = 'green';
        if (student.violation_count >= 5) badgeColor = 'red';
        else if (student.violation_count >= 3) badgeColor = 'orange';
        else if (student.violation_count >= 2) badgeColor = 'yellow';
        
        scanLog.unshift({student, time});
        
        const logHTML = scanLog.map((log, index) => `
            <div class="bg-white rounded-lg p-4 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 border border-slate-200 hover:border-blue-300 transition-colors fade-in shadow-sm" style="animation-delay: ${index * 0.05}s">
                <div class="flex-1">
                    <p class="font-semibold text-slate-800 text-sm md:text-base">${log.student.name}</p>
                    <p class="text-xs md:text-sm text-slate-500">${log.student.student_id} • ${log.student.email}</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <p class="text-${badgeColor}-600 font-bold text-sm md:text-base">Strike #${log.student.violation_count}</p>
                        <p class="text-xs text-slate-400">${log.time}</p>
                    </div>
                    ${log.student.violation_count >= 3 ? '<span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full animate-pulse font-medium">⚠️ Alert</span>' : ''}
                </div>
            </div>
        `).join('');
        
        document.getElementById('scanLog').innerHTML = logHTML || '<p class="text-center text-slate-400 py-8 text-sm">No scans yet</p>';
    }

    function clearLog() {
        if (confirm('Clear all scan logs from this session?')) {
            scanLog = [];
            document.getElementById('scanLog').innerHTML = '<p class="text-center text-slate-400 py-8 text-sm">Log cleared. Waiting for scans...</p>';
        }
    }

    // Auto-focus on page load to ensure RFID scanner works
    window.addEventListener('load', function() {
        document.body.focus();
    });
    </script>
</body>
</html>
