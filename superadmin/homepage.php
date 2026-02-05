<?php
require_once __DIR__ . '/../db.php';

// Check if super admin is logged in
if (!isset($_SESSION['superadmin_logged_in']) || $_SESSION['superadmin_logged_in'] !== true) {
    header('Location: superadmin_login.php');
    exit;
}

$page_title = 'Admin Management Dashboard';

// Get statistics
try {
    $pdo = pdo();
    
    // Total admins
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'");
    $totalAdmins = $stmt->fetchColumn();
    
    // Active admins (check in admin_accounts or default to Active status)
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM users u 
        LEFT JOIN admin_accounts aa ON u.id = aa.user_id 
        WHERE u.role = 'Admin' AND (aa.status = 'Active' OR aa.status IS NULL)
    ");
    $activeAdmins = $stmt->fetchColumn();
    
    // Inactive/Suspended admins
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM admin_accounts WHERE status IN ('Inactive', 'Suspended')
    ");
    $inactiveAdmins = $stmt->fetchColumn();
    
    // Get all admins with their details
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.student_id,
            u.created_at,
            u.last_login,
            u.status as user_status,
            COALESCE(aa.status, 'Active') as admin_status,
            aa.created_by,
            aa.notes,
            sa.username as created_by_name
        FROM users u
        LEFT JOIN admin_accounts aa ON u.id = aa.user_id
        LEFT JOIN super_admins sa ON aa.created_by = sa.id
        WHERE u.role = 'Admin'
        ORDER BY u.created_at DESC
    ");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent audit logs
    $stmt = $pdo->query("
        SELECT 
            sal.id,
            sal.action,
            sal.details,
            sal.ip_address,
            sal.created_at,
            sa.username as admin_name,
            u.name as target_name
        FROM superadmin_audit_log sal
        JOIN super_admins sa ON sal.super_admin_id = sa.id
        LEFT JOIN admin_accounts aa ON sal.target_admin_id = aa.id
        LEFT JOIN users u ON aa.user_id = u.id
        ORDER BY sal.created_at DESC
        LIMIT 10
    ");
    $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Super Admin Dashboard error: " . $e->getMessage());
    $totalAdmins = 0;
    $activeAdmins = 0;
    $inactiveAdmins = 0;
    $admins = [];
    $auditLogs = [];
}

// Include header
include 'includes/header.php';
?>

<!-- Page Header -->
<div class="mb-8 fade-in">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 glass-effect rounded-xl p-6 shadow-lg">
        <div>
            <h1 class="text-2xl font-bold text-slate-800"><?php echo e($page_title); ?></h1>
            <p class="text-slate-600 mt-1">Manage administrator accounts for the PCU RFID System</p>
        </div>
        <button 
            onclick="openAddAdminModal()"
            class="inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-[#0056b3] to-[#003d82] text-white rounded-xl font-semibold btn-hover shadow-lg"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
            </svg>
            Add New Admin
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
    <!-- Total Admins -->
    <div class="glass-effect rounded-xl shadow-lg p-6 card-hover fade-in" style="animation-delay: 0.1s;">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-slate-500 mb-1">Total Admins</p>
                <p class="text-3xl font-bold text-slate-800"><?php echo $totalAdmins; ?></p>
            </div>
            <div class="w-14 h-14 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
            </div>
        </div>
    </div>
    
    <!-- Active Admins -->
    <div class="glass-effect rounded-xl shadow-lg p-6 card-hover fade-in" style="animation-delay: 0.2s;">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-slate-500 mb-1">Active Admins</p>
                <p class="text-3xl font-bold text-green-600"><?php echo $activeAdmins; ?></p>
            </div>
            <div class="w-14 h-14 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
        </div>
    </div>
    
    <!-- Inactive Admins -->
    <div class="glass-effect rounded-xl shadow-lg p-6 card-hover fade-in" style="animation-delay: 0.3s;">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-slate-500 mb-1">Inactive/Suspended</p>
                <p class="text-3xl font-bold text-amber-600"><?php echo $inactiveAdmins; ?></p>
            </div>
            <div class="w-14 h-14 bg-gradient-to-br from-amber-500 to-amber-600 rounded-xl flex items-center justify-center shadow-lg">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Admin List -->
<div class="glass-effect rounded-xl shadow-lg overflow-hidden mb-8 fade-in" style="animation-delay: 0.4s;">
    <div class="px-6 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-lg font-bold text-slate-800">Administrator Accounts</h2>
            <p class="text-sm text-slate-500">Manage all admin users who can access the admin panel</p>
        </div>
        <div class="relative">
            <input 
                type="text" 
                id="searchAdmins" 
                placeholder="Search admins..."
                class="pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#0056b3] focus:border-transparent text-sm w-full sm:w-64"
            >
            <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
    </div>
    
    <?php if (empty($admins)): ?>
    <div class="p-12 text-center">
        <svg class="w-16 h-16 text-slate-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
        </svg>
        <h3 class="text-lg font-semibold text-slate-600 mb-2">No Admins Found</h3>
        <p class="text-slate-500 mb-4">Get started by creating your first admin account.</p>
        <button 
            onclick="openAddAdminModal()"
            class="inline-flex items-center gap-2 px-4 py-2 bg-[#0056b3] text-white rounded-lg font-medium hover:bg-[#003d82] transition-colors"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
            </svg>
            Add Admin
        </button>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full" id="adminsTable">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Admin</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Email</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Created</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Last Login</th>
                    <th class="px-6 py-4 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($admins as $admin): ?>
                <tr class="hover:bg-slate-50 transition-colors admin-row" data-name="<?php echo e(strtolower($admin['name'])); ?>" data-email="<?php echo e(strtolower($admin['email'])); ?>">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-[#0056b3] to-[#003d82] rounded-full flex items-center justify-center flex-shrink-0">
                                <span class="text-white font-semibold text-sm"><?php echo strtoupper(substr($admin['name'], 0, 2)); ?></span>
                            </div>
                            <div>
                                <p class="font-semibold text-slate-800"><?php echo e($admin['name']); ?></p>
                                <p class="text-xs text-slate-500">ID: <?php echo e($admin['student_id']); ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <p class="text-slate-600"><?php echo e($admin['email']); ?></p>
                    </td>
                    <td class="px-6 py-4">
                        <?php 
                        $statusClass = 'bg-green-100 text-green-700';
                        if ($admin['admin_status'] === 'Inactive') {
                            $statusClass = 'bg-slate-100 text-slate-600';
                        } elseif ($admin['admin_status'] === 'Suspended') {
                            $statusClass = 'bg-red-100 text-red-700';
                        }
                        ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                            <?php echo e($admin['admin_status']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <p class="text-slate-600 text-sm"><?php echo date('M j, Y', strtotime($admin['created_at'])); ?></p>
                        <?php if ($admin['created_by_name']): ?>
                        <p class="text-xs text-slate-400">by <?php echo e($admin['created_by_name']); ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($admin['last_login']): ?>
                        <p class="text-slate-600 text-sm"><?php echo date('M j, Y g:i A', strtotime($admin['last_login'])); ?></p>
                        <?php else: ?>
                        <p class="text-slate-400 text-sm">Never</p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center justify-center gap-2">
                            <?php if ($admin['admin_status'] === 'Active'): ?>
                            <button 
                                onclick="toggleAdminStatus(<?php echo $admin['id']; ?>, 'Suspended')"
                                class="p-2 text-amber-600 hover:bg-amber-50 rounded-lg transition-colors" 
                                title="Suspend Admin"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                </svg>
                            </button>
                            <?php else: ?>
                            <button 
                                onclick="toggleAdminStatus(<?php echo $admin['id']; ?>, 'Active')"
                                class="p-2 text-green-600 hover:bg-green-50 rounded-lg transition-colors" 
                                title="Activate Admin"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </button>
                            <?php endif; ?>
                            <button 
                                onclick="confirmDeleteAdmin(<?php echo $admin['id']; ?>, '<?php echo e(addslashes($admin['name'])); ?>')"
                                class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" 
                                title="Remove Admin"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Recent Activity / Audit Log -->
<div class="glass-effect rounded-xl shadow-lg overflow-hidden fade-in" style="animation-delay: 0.5s;">
    <div class="px-6 py-4 border-b border-slate-200">
        <h2 class="text-lg font-bold text-slate-800">Recent Activity</h2>
        <p class="text-sm text-slate-500">Audit log of all super admin actions</p>
    </div>
    
    <?php if (empty($auditLogs)): ?>
    <div class="p-8 text-center">
        <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
        </svg>
        <p class="text-slate-500">No activity recorded yet</p>
    </div>
    <?php else: ?>
    <div class="divide-y divide-slate-100 max-h-96 overflow-y-auto">
        <?php foreach ($auditLogs as $log): ?>
        <div class="px-6 py-4 hover:bg-slate-50 transition-colors">
            <div class="flex items-start gap-4">
                <?php
                $actionIcon = '';
                $actionColor = 'text-slate-400';
                switch ($log['action']) {
                    case 'LOGIN':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>';
                        $actionColor = 'text-blue-500';
                        break;
                    case 'LOGOUT':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>';
                        $actionColor = 'text-slate-500';
                        break;
                    case 'CREATE_ADMIN':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>';
                        $actionColor = 'text-green-500';
                        break;
                    case 'DELETE_ADMIN':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>';
                        $actionColor = 'text-red-500';
                        break;
                    case 'SUSPEND_ADMIN':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>';
                        $actionColor = 'text-amber-500';
                        break;
                    case 'ACTIVATE_ADMIN':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>';
                        $actionColor = 'text-green-500';
                        break;
                    default:
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>';
                }
                ?>
                <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 <?php echo $actionColor; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?php echo $actionIcon; ?>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-slate-800">
                        <?php echo e($log['admin_name']); ?> 
                        <span class="text-slate-500 font-normal">
                            <?php
                            switch ($log['action']) {
                                case 'LOGIN': echo 'logged in'; break;
                                case 'LOGOUT': echo 'logged out'; break;
                                case 'CREATE_ADMIN': echo 'created admin ' . e($log['target_name'] ?? ''); break;
                                case 'DELETE_ADMIN': echo 'removed admin ' . e($log['target_name'] ?? ''); break;
                                case 'SUSPEND_ADMIN': echo 'suspended admin ' . e($log['target_name'] ?? ''); break;
                                case 'ACTIVATE_ADMIN': echo 'activated admin ' . e($log['target_name'] ?? ''); break;
                                default: echo $log['action'];
                            }
                            ?>
                        </span>
                    </p>
                    <p class="text-xs text-slate-400 mt-1">
                        <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?> • IP: <?php echo e($log['ip_address']); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add Admin Modal -->
<div id="addAdminModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md transform transition-all scale-95 opacity-0" id="addAdminModalContent">
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
            <h3 class="text-xl font-bold text-slate-800">Add New Admin</h3>
            <button onclick="closeAddAdminModal()" class="p-2 hover:bg-slate-100 rounded-lg transition-colors">
                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <form id="addAdminForm" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Full Name</label>
                <input 
                    type="text" 
                    name="name" 
                    required
                    placeholder="Enter full name"
                    class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#0056b3] focus:border-transparent"
                >
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Email Address</label>
                <input 
                    type="email" 
                    name="email" 
                    required
                    placeholder="Enter email address"
                    class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#0056b3] focus:border-transparent"
                >
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Admin ID</label>
                <input 
                    type="text" 
                    name="student_id" 
                    required
                    placeholder="Enter admin ID (e.g., ADMIN-001)"
                    class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#0056b3] focus:border-transparent"
                >
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Password</label>
                <div class="relative">
                    <input 
                        type="password" 
                        name="password" 
                        id="newAdminPassword"
                        required
                        minlength="8"
                        placeholder="Enter password (min 8 characters)"
                        class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#0056b3] focus:border-transparent pr-12"
                    >
                    <button 
                        type="button" 
                        onclick="toggleNewPassword()"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                    >
                        <svg id="newEyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Confirm Password</label>
                <input 
                    type="password" 
                    name="confirm_password" 
                    id="confirmAdminPassword"
                    required
                    placeholder="Confirm password"
                    class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#0056b3] focus:border-transparent"
                >
            </div>
            
            <div class="flex gap-3 pt-4">
                <button 
                    type="button" 
                    onclick="closeAddAdminModal()"
                    class="flex-1 px-4 py-3 border border-slate-300 text-slate-700 rounded-xl font-semibold hover:bg-slate-50 transition-colors"
                >
                    Cancel
                </button>
                <button 
                    type="submit"
                    class="flex-1 px-4 py-3 bg-gradient-to-r from-[#0056b3] to-[#003d82] text-white rounded-xl font-semibold btn-hover"
                >
                    Create Admin
                </button>
            </div>
        </form>
    </div>
</div>

</main>

<!-- Footer -->
<footer class="glass-effect border-t border-slate-200 mt-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
        <p class="text-center text-sm text-slate-500">
            © <?php echo date('Y'); ?> Philippine Christian University • Super Admin Panel
        </p>
    </div>
</footer>

<script>
// CSRF Token
const csrfToken = '<?php echo e($_SESSION['csrf_token']); ?>';

// Search functionality
document.getElementById('searchAdmins').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('.admin-row');
    
    rows.forEach(row => {
        const name = row.dataset.name;
        const email = row.dataset.email;
        
        if (name.includes(searchTerm) || email.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});

// Add Admin Modal
function openAddAdminModal() {
    const modal = document.getElementById('addAdminModal');
    const content = document.getElementById('addAdminModalContent');
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    setTimeout(() => {
        content.classList.remove('scale-95', 'opacity-0');
        content.classList.add('scale-100', 'opacity-100');
    }, 10);
}

function closeAddAdminModal() {
    const modal = document.getElementById('addAdminModal');
    const content = document.getElementById('addAdminModalContent');
    
    content.classList.remove('scale-100', 'opacity-100');
    content.classList.add('scale-95', 'opacity-0');
    
    setTimeout(() => {
        modal.classList.remove('flex');
        modal.classList.add('hidden');
        document.getElementById('addAdminForm').reset();
    }, 200);
}

// Close modal on backdrop click
document.getElementById('addAdminModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAddAdminModal();
    }
});

// Toggle password visibility
function toggleNewPassword() {
    const input = document.getElementById('newAdminPassword');
    const icon = document.getElementById('newEyeIcon');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
        `;
    } else {
        input.type = 'password';
        icon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        `;
    }
}

// Add Admin Form Submit
document.getElementById('addAdminForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    // Validate passwords match
    if (formData.get('password') !== formData.get('confirm_password')) {
        Swal.fire({
            icon: 'error',
            title: 'Password Mismatch',
            text: 'Passwords do not match. Please try again.',
            confirmButtonColor: '#0056b3'
        });
        return;
    }
    
    // Validate password length
    if (formData.get('password').length < 8) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Password',
            text: 'Password must be at least 8 characters long.',
            confirmButtonColor: '#0056b3'
        });
        return;
    }
    
    try {
        const response = await fetch('add_admin.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            closeAddAdminModal();
            Swal.fire({
                icon: 'success',
                title: 'Admin Created!',
                text: data.message,
                confirmButtonColor: '#0056b3'
            }).then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.error || 'Failed to create admin',
                confirmButtonColor: '#0056b3'
            });
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An unexpected error occurred. Please try again.',
            confirmButtonColor: '#0056b3'
        });
    }
});

// Toggle Admin Status
async function toggleAdminStatus(adminId, newStatus) {
    const actionText = newStatus === 'Active' ? 'activate' : 'suspend';
    
    const result = await Swal.fire({
        title: `${actionText.charAt(0).toUpperCase() + actionText.slice(1)} Admin?`,
        text: `Are you sure you want to ${actionText} this admin account?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: newStatus === 'Active' ? '#10b981' : '#f59e0b',
        cancelButtonColor: '#64748b',
        confirmButtonText: `Yes, ${actionText} it!`
    });
    
    if (result.isConfirmed) {
        try {
            const response = await fetch('update_admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    admin_id: adminId,
                    status: newStatus,
                    csrf_token: csrfToken
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    confirmButtonColor: '#0056b3'
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.error || 'Failed to update admin status',
                    confirmButtonColor: '#0056b3'
                });
            }
        } catch (error) {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An unexpected error occurred. Please try again.',
                confirmButtonColor: '#0056b3'
            });
        }
    }
}

// Confirm Delete Admin
async function confirmDeleteAdmin(adminId, adminName) {
    const result = await Swal.fire({
        title: 'Delete Admin?',
        html: `Are you sure you want to permanently delete <strong>${adminName}</strong>?<br><br><span class="text-red-600 text-sm">This action cannot be undone!</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete it!'
    });
    
    if (result.isConfirmed) {
        try {
            const response = await fetch('remove_admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    admin_id: adminId,
                    csrf_token: csrfToken
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: data.message,
                    confirmButtonColor: '#0056b3'
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.error || 'Failed to delete admin',
                    confirmButtonColor: '#0056b3'
                });
            }
        } catch (error) {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An unexpected error occurred. Please try again.',
                confirmButtonColor: '#0056b3'
            });
        }
    }
}
</script>
</body>
</html>
