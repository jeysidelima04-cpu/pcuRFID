<?php require_once __DIR__ . '/db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>PCU RFID | Signup</title>
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
    
    /* Mobile Responsive Styles */
    @media (max-width: 768px) {
      /* Container adjustments */
      .min-h-screen {
        min-height: auto !important;
        padding: 1rem 0 !important;
      }
      
      /* Form container */
      .max-w-lg {
        max-width: 100% !important;
        margin: 0 !important;
      }
      
      .p-8 {
        padding: 1.5rem !important;
      }
      
      /* Logo size */
      .w-24.h-24 {
        width: 4rem !important;
        height: 4rem !important;
      }
      
      .mb-6 {
        margin-bottom: 1rem !important;
      }
      
      /* Title adjustments */
      .text-3xl {
        font-size: 1.5rem !important;
      }
      
      .text-base {
        font-size: 0.875rem !important;
      }
      
      /* Form grid - single column on mobile */
      .grid.md\\:grid-cols-2 {
        grid-template-columns: 1fr !important;
      }
      
      .gap-6 {
        gap: 1rem !important;
      }
      
      /* Input fields */
      .h-11 {
        height: 2.75rem !important;
      }
      
      .px-4 {
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
      }
      
      /* Spacing */
      .space-y-2 > * + * {
        margin-top: 0.375rem !important;
      }
      
      .mb-8 {
        margin-bottom: 1.5rem !important;
      }
      
      /* Error messages */
      .p-4 {
        padding: 0.75rem !important;
      }
    }
    
    @media (max-width: 480px) {
      .p-8 {
        padding: 1rem !important;
      }
      
      .w-24.h-24 {
        width: 3.5rem !important;
        height: 3.5rem !important;
      }
      
      .text-3xl {
        font-size: 1.25rem !important;
      }
    }
  </style>
</head>
<body class="text-slate-800 bg-pcu min-h-screen">
  <div class="min-h-screen flex items-center justify-center p-4 relative z-10">
    <div class="w-full max-w-lg bg-white/90 shadow-2xl rounded-2xl p-8 transition-all fade-in">
      <div class="mb-8 text-center">
        <a href="login.php" class="inline-block hover:opacity-80 transition-opacity">
          <img src="pcu-logo.png" alt="PCU Logo" class="w-24 h-24 mx-auto mb-6">
        </a>
        <h1 class="text-3xl font-semibold text-sky-700 mb-2">Create your account</h1>
        <p class="text-base text-slate-600">Verify via email to activate</p>
      </div>

      <?php if (isset($_GET['error'])): ?>
        <div class="mb-6 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-4 shadow-sm"><?php echo htmlspecialchars($_GET['error']); ?></div>
      <?php endif; ?>

      <!-- Google Sign-Up Button -->
      <?php
      $show_google_button = false;
      $google_signup_url = '#';
      
      if (file_exists('vendor/autoload.php') && file_exists('config/google_config.php')) {
          try {
              require_once 'vendor/autoload.php';
              require_once 'config/google_config.php';
              
              if (class_exists('Google_Client')) {
                  $google_client = new Google_Client();
                  $google_client->setClientId(GOOGLE_CLIENT_ID);
                  $google_client->setClientSecret(GOOGLE_CLIENT_SECRET);
                  $google_client->setRedirectUri(GOOGLE_REDIRECT_URI);
                  $google_client->addScope("email");
                  $google_client->addScope("profile");
                  
                  // OAuth state parameter for CSRF protection
                  // Add timestamp to prevent replay attacks (state expires in 10 minutes)
                  $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
                  $_SESSION['oauth_state_time'] = time();
                  $google_client->setState($_SESSION['oauth_state']);
                  
                  $google_signup_url = $google_client->createAuthUrl();
                  $show_google_button = true;
              }
          } catch (Exception $e) {
              // Google library not available, hide button
              $show_google_button = false;
          }
      }
      ?>
      
      <?php if ($show_google_button): ?>
      <a href="<?php echo htmlspecialchars($google_signup_url); ?>" 
         class="flex items-center justify-center w-full h-11 bg-white text-slate-700 text-base font-medium rounded-lg border-2 border-slate-300
                shadow-sm transition duration-150
                hover:bg-slate-50 hover:border-slate-400 hover:shadow-md
                active:transform active:scale-[0.98]">
        <svg class="w-5 h-5 mr-2" viewBox="0 0 24 24">
          <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
          <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
          <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
          <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
        </svg>
        Sign up with Google
      </a>

      <!-- OR Divider -->
      <div class="relative flex items-center my-6">
        <div class="flex-grow border-t border-slate-300"></div>
        <span class="flex-shrink mx-4 text-sm text-slate-500 font-medium">OR</span>
        <div class="flex-grow border-t border-slate-300"></div>
      </div>
      <?php endif; ?>

      <form action="auth.php" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6" id="signupForm" novalidate>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="signup">

        <div class="md:col-span-1 space-y-2">
          <label class="block text-base font-medium text-slate-700">Student ID</label>
          <input 
            type="text" 
            name="student_id" 
            required 
            pattern="[0-9\-]+"
            title="Student ID must contain only numbers and dashes"
            class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 placeholder-slate-400
                   shadow-sm transition duration-150
                   hover:border-slate-300
                   focus:border-sky-500 focus:ring-4 focus:ring-sky-100 focus:outline-none
                   invalid:border-red-300 invalid:text-red-600
                   invalid:focus:border-red-500 invalid:focus:ring-red-100" 
            placeholder="e.g., 23-12345"
            oninput="this.value = this.value.replace(/[^0-9\-]/g, '')"
          >
        </div>
        <div class="md:col-span-2 space-y-2">
          <label class="block text-base font-medium text-slate-700">First Name</label>
          <input 
            type="text" 
            name="first_name" 
            required 
            pattern="[A-Za-z\s\.]+"
            title="First name must contain only letters, spaces, and periods"
            class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 placeholder-slate-400
                   shadow-sm transition duration-150
                   hover:border-slate-300
                   focus:border-sky-500 focus:ring-4 focus:ring-sky-100 focus:outline-none
                   invalid:border-red-300 invalid:text-red-600
                   invalid:focus:border-red-500 invalid:focus:ring-red-100" 
            placeholder="First name"
            oninput="this.value = this.value.replace(/[^A-Za-z\s\.]/g, '')"
          >
        </div>
        <div class="md:col-span-2 space-y-2">
          <label class="block text-base font-medium text-slate-700">Middle Name</label>
          <input 
            type="text" 
            name="middle_name" 
            pattern="[A-Za-z\s\.]+"
            title="Middle name must contain only letters, spaces, and periods"
            class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 placeholder-slate-400
                   shadow-sm transition duration-150
                   hover:border-slate-300
                   focus:border-sky-500 focus:ring-4 focus:ring-sky-100 focus:outline-none
                   invalid:border-red-300 invalid:text-red-600
                   invalid:focus:border-red-500 invalid:focus:ring-red-100" 
            placeholder="Middle name (optional)"
            oninput="this.value = this.value.replace(/[^A-Za-z\s\.]/g, '')"
          >
        </div>
        <div class="md:col-span-2 space-y-2">
          <label class="block text-base font-medium text-slate-700">Surname</label>
          <input 
            type="text" 
            name="surname" 
            required 
            pattern="[A-Za-z\s\.]+"
            title="Surname must contain only letters, spaces, and periods"
            class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 placeholder-slate-400
                   shadow-sm transition duration-150
                   hover:border-slate-300
                   focus:border-sky-500 focus:ring-4 focus:ring-sky-100 focus:outline-none
                   invalid:border-red-300 invalid:text-red-600
                   invalid:focus:border-red-500 invalid:focus:ring-red-100" 
            placeholder="Surname"
            oninput="this.value = this.value.replace(/[^A-Za-z\s\.]/g, '')"
          >
        </div>
        <div class="md:col-span-2 space-y-2">
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
        <div class="md:col-span-1 space-y-2">
          <label class="block text-base font-medium text-slate-700">Password</label>
          <div class="relative">
            <input 
              type="password" 
              name="password" 
              minlength="8"
              pattern="^(?=.*[A-Z])(?=.*[!@#$%^&*])(?=.*[0-9])(?=.*[a-z]).{8,}$"
              required 
              class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 placeholder-slate-400
                     shadow-sm transition duration-150
                     hover:border-slate-300
                     focus:border-sky-500 focus:ring-4 focus:ring-sky-100 focus:outline-none
                     invalid:border-red-300 invalid:text-red-600
                     invalid:focus:border-red-500 invalid:focus:ring-red-100
                     peer" 
              placeholder="Enter password"
              oninput="validatePassword(this)"
            >
            <div class="hidden peer-invalid:block text-xs text-red-600 mt-1">
              Password must contain:
              <ul class="list-disc list-inside">
                <li>At least 8 characters</li>
                <li>One uppercase letter</li>
                <li>One special character (!@#$%^&*)</li>
                <li>One number</li>
              </ul>
            </div>
          </div>
        </div>

        <script>
          function validatePassword(input) {
            const value = input.value;
            const hasUpperCase = /[A-Z]/.test(value);
            const hasSpecialChar = /[!@#$%^&*]/.test(value);
            const hasNumber = /[0-9]/.test(value);
            const hasLowerCase = /[a-z]/.test(value);
            const isLongEnough = value.length >= 8;
            
            const isValid = hasUpperCase && hasSpecialChar && hasNumber && hasLowerCase && isLongEnough;
            
            input.setCustomValidity(isValid ? '' : 'Please meet all password requirements');
            
            // Also validate confirm password if it has a value
            const confirmInput = document.querySelector('input[name="confirm_password"]');
            if (confirmInput.value) {
              confirmInput.setCustomValidity(
                confirmInput.value === value ? '' : 'Passwords must match'
              );
            }
          }
        </script>
        <div class="md:col-span-1 space-y-2">
          <label class="block text-base font-medium text-slate-700">Confirm password</label>
          <div class="relative">
            <input 
              type="password" 
              name="confirm_password" 
              minlength="8" 
              required 
              class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 placeholder-slate-400
                     shadow-sm transition duration-150
                     hover:border-slate-300
                     focus:border-sky-500 focus:ring-4 focus:ring-sky-100 focus:outline-none
                     invalid:border-red-300 invalid:text-red-600
                     invalid:focus:border-red-500 invalid:focus:ring-red-100
                     peer" 
              placeholder="Re-enter password"
              oninput="validateConfirmPassword(this)"
            >
            <div class="hidden peer-invalid:block text-xs text-red-600 mt-1">Passwords must match</div>
          </div>
          
          <script>
            function validateConfirmPassword(input) {
              const password = document.querySelector('input[name="password"]').value;
              input.setCustomValidity(
                input.value === password ? '' : 'Passwords must match'
              );
            }
          </script>
        </div>
        <div class="md:col-span-2 space-y-2">
          <label class="block text-base font-medium text-slate-700">Student Type</label>
          <div class="relative">
            <input 
              type="hidden" 
              name="student_type" 
              value="college"
            >
          <div class="w-full h-11 px-4 pr-10 rounded-lg border-2 border-slate-200 bg-white text-base text-slate-800 flex items-center">
              <span style="line-height: 1; margin: auto 0;">College Student</span>
          </div>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center justify-center px-3 text-slate-600">
              <svg class="h-4 w-4 transform transition-transform duration-200 ease-in-out" 
                   fill="none" 
                   stroke="currentColor" 
                   viewBox="0 0 24 24">
                <path stroke-linecap="round" 
                      stroke-linejoin="round" 
                      stroke-width="2" 
                      d="M19 9l-7 7-7-7">
                </path>
              </svg>
            </div>
          </div>
        </div>

        <style>
          select:focus + div svg {
            transform: rotate(180deg);
          }
          
          @keyframes selectOpen {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
          }
          
          select option {
            padding: 0.75rem 1rem;
            transition: all 0.2s;
            animation: selectOpen 0.2s ease-out;
          }
          
          select:hover {
            transform: translateY(-1px);
          }
          
          select:active {
            transform: translateY(0);
          }
        </style>

        <div class="md:col-span-2">
          <button 
            type="submit" 
            class="w-full h-11 bg-sky-600 text-white text-base font-medium rounded-lg
                   shadow-md shadow-sky-100 transition duration-150
                   hover:bg-sky-700 hover:shadow-lg hover:shadow-sky-100
                   active:transform active:scale-[0.98]"
          >
            Create account
          </button>
        </div>
      </form>

      <p class="mt-6 text-center text-sm text-slate-500">
        Already have an account?
        <a href="login.php" class="text-sky-700 hover:underline">Sign in</a>
      </p>
    </div>
  </div>

  <div id="toast-container" class="fixed bottom-4 right-4 space-y-2 z-50"></div>
  <script src="assets/js/app.js"></script>
</body>
</html>
