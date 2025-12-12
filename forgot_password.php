<?php 
// PHASE 3: Redirect to Google-only login
// Password reset is no longer available - users must use Google Sign-In
header('Location: login.php?message=google_only');
exit;

require_once __DIR__ . '/db.php'; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>PCU RFID | Reset Password</title>
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
    <div class="w-full max-w-md bg-white/90 shadow-2xl rounded-2xl p-8 transition-all fade-in">
      <div class="mb-8 text-center">
        <a href="login.php" class="inline-block hover:opacity-80 transition-opacity">
          <img src="pcu-logo.png" alt="PCU Logo" class="w-24 h-24 mx-auto mb-6">
        </a>
        <h1 class="text-3xl font-semibold text-sky-700 mb-2">Reset Password</h1>
        <p class="text-base text-slate-600">Enter your email to receive reset instructions</p>
      </div>

      <?php if (isset($_SESSION['error'])): ?>
        <div class="mb-6 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-4 shadow-sm"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
      <?php endif; ?>
      <?php if (isset($_SESSION['success'])): ?>
        <div class="mb-6 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg p-4 shadow-sm"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
      <?php endif; ?>

      <form action="reset_password.php" method="POST" class="space-y-6" id="resetForm" novalidate>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="request_reset">
        <div class="space-y-2">
          <label class="block text-base font-medium text-slate-700">Email address</label>
          <input 
            type="email" 
            name="email" 
            required 
            class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 placeholder-slate-400
                   shadow-sm transition duration-150
                   hover:border-slate-300
                   focus:border-sky-500 focus:ring-4 focus:ring-sky-100 focus:outline-none
                   invalid:border-red-300 invalid:text-red-600
                   invalid:focus:border-red-500 invalid:focus:ring-red-100" 
            placeholder="you@pcu.edu.ph"
          >
        </div>
        <button 
          type="submit" 
          class="w-full h-11 bg-sky-600 text-white text-base font-medium rounded-lg
                 shadow-md shadow-sky-100 transition duration-150
                 hover:bg-sky-700 hover:shadow-lg hover:shadow-sky-100
                 active:transform active:scale-[0.98]"
        >
          Send Reset Link
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-slate-500">
        Remember your password?
        <a href="login.php" class="text-sky-700 hover:underline">Sign in</a>
      </p>
    </div>
  </div>

  <div id="toast-container" class="fixed bottom-4 right-4 space-y-2 z-50"></div>
  <script src="assets/js/app.js"></script>
</body>
</html>