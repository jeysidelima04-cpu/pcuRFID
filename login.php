<?php require_once __DIR__ . '/db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>PCU RFID | Login</title>
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
        <h1 class="text-3xl font-semibold text-sky-700 mb-2">Welcome Back</h1>
        <p class="text-base text-slate-600">RFID-Enabled Identity Verification</p>
      </div>

      <?php if (isset($_GET['error'])): ?>
        <div class="mb-6 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-4 shadow-sm"><?php echo htmlspecialchars($_GET['error']); ?></div>
      <?php endif; ?>
      <?php if (isset($_GET['toast'])): ?>
        <div data-toast="<?php echo htmlspecialchars($_GET['toast']); ?>"></div>
      <?php endif; ?>

      <form action="auth.php" method="POST" class="space-y-5" id="loginForm" novalidate>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="login">
        <div style="margin-bottom: 0.25rem;">
          <label class="block text-base font-medium text-slate-700" style="margin-bottom: 0.25rem;">Email address</label>
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
        <div>
          <label class="block text-base font-medium text-slate-700" style="margin-bottom: 0.25rem;">Password</label>
          <div class="relative">
            <input 
              type="password" 
              name="password" 
              id="password"
              required 
              minlength="8" 
              class="w-full h-11 px-4 pr-12 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 placeholder-slate-400
                     shadow-sm transition duration-150
                     hover:border-slate-300
                     focus:border-sky-500 focus:ring-4 focus:ring-sky-100 focus:outline-none
                     invalid:border-red-300 invalid:text-red-600
                     invalid:focus:border-red-500 invalid:focus:ring-red-100" 
              placeholder="••••••••"
            >
            <button 
              type="button" 
              onclick="togglePassword()"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors focus:outline-none"
              aria-label="Toggle password visibility"
            >
              <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
              <svg id="eyeOffIcon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
              </svg>
            </button>
          </div>
        </div>
        <button 
          type="submit" 
          class="w-full h-11 bg-sky-600 text-white text-base font-medium rounded-lg
                 shadow-md shadow-sky-100 transition duration-150
                 hover:bg-sky-700 hover:shadow-lg hover:shadow-sky-100
                 active:transform active:scale-[0.98]"
        >
          Sign in
        </button>
      </form>

      <div class="mt-4 text-center">
        <a href="forgot_password.php" class="text-sm text-sky-700 hover:underline">Forgot password?</a>
      </div>

      <p class="mt-6 text-center text-sm text-slate-500">
        Don’t have an account?
        <a href="signup.php" class="text-sky-700 hover:underline">Create one</a>
      </p>
    </div>
  </div>

  <div id="toast-container" class="fixed bottom-4 right-4 space-y-2 z-50"></div>
  <script src="assets/js/app.js"></script>
  <script>
    function togglePassword() {
      const passwordInput = document.getElementById('password');
      const eyeIcon = document.getElementById('eyeIcon');
      const eyeOffIcon = document.getElementById('eyeOffIcon');
      
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        eyeIcon.classList.add('hidden');
        eyeOffIcon.classList.remove('hidden');
      } else {
        passwordInput.type = 'password';
        eyeIcon.classList.remove('hidden');
        eyeOffIcon.classList.add('hidden');
      }
    }
  </script>
</body>
</html>
