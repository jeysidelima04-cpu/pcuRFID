<?php
/**
 * Post–Google Sign-In completion step for NEW students.
 * - Shows Terms & Conditions (accept/decline)
 * - Collects Parent/Guardian full name, email, and contact number
 * - Creates the student account as Pending verification
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/terms_helper.php';

send_security_headers();
send_no_cache_headers();

$pdo = pdo();

$signup = $_SESSION['google_signup'] ?? null;
if (!is_array($signup)) {
    header('Location: login.php');
    exit;
}

$startedAt = (int)($signup['started_at'] ?? 0);
if ($startedAt <= 0 || (time() - $startedAt) > 900) {
    unset($_SESSION['google_signup']);
    $_SESSION['error'] = 'Signup session expired. Please try signing in with Google again.';
    header('Location: login.php');
    exit;
}

$studentName = (string)($signup['name'] ?? '');
$studentEmail = (string)($signup['email'] ?? '');
$googleId = (string)($signup['google_id'] ?? '');

if ($studentName === '' || $studentEmail === '' || $googleId === '') {
    unset($_SESSION['google_signup']);
    $_SESSION['error'] = 'Incomplete Google sign-in details. Please try again.';
    header('Location: login.php');
    exit;
}

// Only allow this page for truly NEW signups.
// If the Google account/email is already registered, do not allow direct URL access.
try {
  $stmt = $pdo->prepare('SELECT id FROM users WHERE google_id = ? OR email = ? LIMIT 1');
  $stmt->execute([$googleId, $studentEmail]);
  if ($stmt->fetch()) {
    unset($_SESSION['google_signup']);
    $_SESSION['info'] = 'Your account is already registered. If verification is pending, please wait for approval.';
    header('Location: login.php');
    exit;
  }
} catch (Throwable $e) {
  // If we cannot validate uniqueness, fail closed for safety.
  unset($_SESSION['google_signup']);
  $_SESSION['error'] = 'Unable to continue registration at this time. Please try again.';
  header('Location: login.php');
  exit;
}

$pageError = '';

function users_terms_columns_available(PDO $pdo): bool {
    try {
        $stmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('terms_accepted_at','terms_version')");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_map('strtolower', array_map('strval', $cols));
        return in_array('terms_accepted_at', $cols, true) && in_array('terms_version', $cols, true);
    } catch (Throwable $e) {
        return false;
    }
}

function split_full_name(string $fullName): array {
    $fullName = trim(preg_replace('/\s+/', ' ', $fullName));
    if ($fullName === '') {
        return ['', ''];
    }

    $parts = explode(' ', $fullName);
    if (count($parts) === 1) {
        return [$parts[0], $parts[0]];
    }

    $lastName = array_pop($parts);
    $firstName = implode(' ', $parts);
    return [$firstName, $lastName];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'decline') {
        unset($_SESSION['google_signup']);
        $_SESSION['error'] = 'You declined the Terms and Conditions. Registration was cancelled.';
        header('Location: login.php');
        exit;
    }

    if ($action === 'complete') {
        $accepted = (string)($_POST['accepted_terms'] ?? '');
        $guardianFullName = trim((string)($_POST['guardian_full_name'] ?? ''));
        $guardianEmail = strtolower(trim((string)($_POST['guardian_email'] ?? '')));
        $guardianContact = trim((string)($_POST['guardian_contact_number'] ?? ''));

        if ($accepted !== '1') {
            $pageError = 'You must accept the Terms and Conditions to continue.';
        } elseif ($guardianFullName === '' || $guardianEmail === '' || $guardianContact === '') {
            $pageError = 'Please provide Parent/Guardian full name, email, and contact number.';
        } elseif (!filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
            $pageError = 'Please enter a valid Parent/Guardian email address.';
        } else {
            try {
                // Ensure this is still a brand-new signup (avoid duplicates if user refreshes)
                $stmt = $pdo->prepare('SELECT id FROM users WHERE google_id = ? OR email = ? LIMIT 1');
                $stmt->execute([$googleId, $studentEmail]);
                if ($stmt->fetch()) {
                    unset($_SESSION['google_signup']);
                    $_SESSION['info'] = 'Your account is already registered. If verification is pending, please wait for approval.';
                    header('Location: login.php');
                    exit;
                }

                $pdo->beginTransaction();

                $temporaryStudentId = generate_temporary_student_id($pdo);

                $randomPassword = bin2hex(random_bytes(32));
                $hashedPassword = password_hash($randomPassword, PASSWORD_ARGON2ID);

                $termsAt = date('Y-m-d H:i:s');
                $termsVersion = gatewatch_terms_version();

                if (users_terms_columns_available($pdo)) {
                    $insertStmt = $pdo->prepare('
                        INSERT INTO users (student_id, name, email, password, google_id, role, status, created_at, terms_accepted_at, terms_version)
                        VALUES (?, ?, ?, ?, ?, "Student", "Pending", NOW(), ?, ?)
                    ');
                    $insertStmt->execute([
                        $temporaryStudentId,
                        $studentName,
                        $studentEmail,
                        $hashedPassword,
                        $googleId,
                        $termsAt,
                        $termsVersion,
                    ]);
                } else {
                    $insertStmt = $pdo->prepare('
                        INSERT INTO users (student_id, name, email, password, google_id, role, status, created_at)
                        VALUES (?, ?, ?, ?, ?, "Student", "Pending", NOW())
                    ');
                    $insertStmt->execute([
                        $temporaryStudentId,
                        $studentName,
                        $studentEmail,
                        $hashedPassword,
                        $googleId,
                    ]);
                }

                $newUserId = (int)$pdo->lastInsertId();

                // Upsert guardian by email, then link as primary
                [$guardianFirst, $guardianLast] = split_full_name($guardianFullName);

                $stmt = $pdo->prepare('SELECT id FROM guardians WHERE email = ? LIMIT 1');
                $stmt->execute([$guardianEmail]);
                $guardianId = (int)($stmt->fetchColumn() ?: 0);

                if ($guardianId > 0) {
                    // Keep existing relationship; update contact info and name to latest provided.
                    $update = $pdo->prepare('UPDATE guardians SET first_name = ?, last_name = ?, phone_number = ? WHERE id = ?');
                    $update->execute([$guardianFirst ?: 'Guardian', $guardianLast ?: 'Contact', $guardianContact, $guardianId]);
                } else {
                    $insert = $pdo->prepare('
                        INSERT INTO guardians (email, first_name, last_name, phone_number, relationship)
                        VALUES (?, ?, ?, ?, "Guardian")
                    ');
                    $insert->execute([
                        $guardianEmail,
                        $guardianFirst ?: 'Guardian',
                        $guardianLast ?: 'Contact',
                        $guardianContact,
                    ]);
                    $guardianId = (int)$pdo->lastInsertId();
                }

                $link = $pdo->prepare('
                    INSERT INTO student_guardians (student_id, guardian_id, is_primary)
                    VALUES (?, ?, 1)
                ');
                $link->execute([$newUserId, $guardianId]);

                $pdo->commit();

                unset($_SESSION['google_signup']);

                $_SESSION['info'] = 'Your account has been created successfully and is pending verification. You will receive an email once your account is approved by the Student Services Office.';
                header('Location: login.php');
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[PCU RFID] Google signup completion error: ' . $e->getMessage());
                $pageError = 'Registration failed. Please try again or contact support.';
            }
        }
    }
}

$termsTitle = gatewatch_terms_title();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>GateWatch | Complete Registration</title>
  <link rel="icon" type="image/png" href="assets/images/gatewatch-logo.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="assets/js/tailwind.config.js"></script>
  <link rel="stylesheet" href="assets/css/styles.css">
  <style type="text/tailwindcss">
    .glass-card { backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); }
    .bg-noise { pointer-events:none; position:absolute; inset:0; opacity:0.10;
      background-image: radial-gradient(rgba(255,255,255,0.38) 0.45px, transparent 0.45px);
      background-size: 3px 3px; mix-blend-mode: soft-light; z-index: 1; }
    .halo { pointer-events:none; position:absolute; width:18rem; height:18rem; border-radius:9999px;
      background: radial-gradient(circle, rgba(14,165,233,0.30) 0%, rgba(14,165,233,0) 65%);
      filter: blur(12px); }
  </style>
</head>
<body class="text-slate-800 min-h-screen antialiased bg-slate-950">
  <video class="fixed inset-0 w-full h-full object-cover z-0" src="assets/images/PCU MANILA Campus 2025.mp4" autoplay muted loop playsinline></video>
  <div class="fixed inset-0 bg-gradient-to-br from-slate-950/70 via-slate-950/50 to-sky-900/35 z-[1]"></div>
  <div class="bg-noise"></div>
  <div class="halo -top-20 -left-16 z-[2]"></div>
  <div class="halo -bottom-24 -right-16 z-[2]"></div>

  <main class="relative z-[3] min-h-screen flex items-center justify-center px-4 py-10">
    <section class="w-full max-w-2xl glass-card bg-white/14 shadow-2xl rounded-[28px] border border-white/30 p-6 md:p-10 text-white">
      <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 class="text-2xl md:text-3xl font-semibold tracking-tight">Complete your registration</h1>
          <p class="text-sky-100/85 mt-2">Before we create your GateWatch account, please review the Terms and provide emergency contact details.</p>
        </div>
        <a href="login.php" class="inline-flex items-center rounded-full border border-white/25 bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/15 transition">Cancel</a>
      </div>

      <?php if ($pageError !== ''): ?>
        <div class="mb-6 text-sm bg-red-50/95 border border-red-200 rounded-lg p-4 text-red-800">
          <?php echo e($pageError); ?>
        </div>
      <?php endif; ?>

      <div class="rounded-2xl border border-white/20 bg-white/10 p-4 md:p-5">
        <div class="grid gap-3 md:grid-cols-2">
          <div>
            <p class="text-xs text-sky-100/70">Student Name</p>
            <p class="font-semibold text-white break-words"><?php echo e($studentName); ?></p>
          </div>
          <div>
            <p class="text-xs text-sky-100/70">PCU Email Address</p>
            <p class="font-semibold text-white break-words"><?php echo e($studentEmail); ?></p>
          </div>
        </div>
      </div>

      <!-- Step 1: Terms -->
      <div class="mt-6" id="step-terms">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <h2 class="text-lg font-semibold"><?php echo e($termsTitle); ?></h2>
          <a href="terms_and_conditions.php" target="_blank" rel="noopener" class="text-sm text-sky-200 hover:text-white underline underline-offset-4">Open full Terms</a>
        </div>

        <div class="mt-3 max-h-72 overflow-auto rounded-2xl border border-white/20 bg-white/10 p-4 md:p-5">
          <div class="space-y-4 text-sky-50/95 text-sm leading-relaxed [text-align:justify]">
            <?php echo gatewatch_terms_html(); ?>
          </div>
        </div>

        <div class="mt-4 flex items-start gap-3">
          <input id="accept" type="checkbox" class="mt-1 h-4 w-4 rounded border-white/30 bg-white/10 text-sky-400" />
          <label for="accept" class="text-sm text-sky-100/90 [text-align:justify]">
            I have read and I agree to the Terms and Conditions, including my consent for GateWatch to collect and use my
            <strong>Full Name</strong>, <strong>Student ID</strong>, <strong>PCU Email Address</strong>, and my
            <strong>Parent/Guardian full name</strong>, <strong>email</strong>, and <strong>contact number</strong> for
            registration and emergency contact purposes.
          </label>
        </div>

        <div class="mt-5 flex gap-3 flex-wrap">
          <form method="POST" class="inline">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="decline" />
            <button type="submit" class="rounded-full border border-white/25 bg-white/10 px-5 py-2.5 text-sm font-semibold text-white hover:bg-white/15 transition">Decline</button>
          </form>

          <button type="button" id="btn-continue" class="rounded-full bg-sky-500/90 hover:bg-sky-500 px-6 py-2.5 text-sm font-semibold text-slate-900 transition disabled:opacity-50 disabled:cursor-not-allowed">
            Accept & Continue
          </button>
        </div>
      </div>

      <!-- Step 2: Guardian details -->
      <div class="mt-8 hidden" id="step-guardian">
        <h2 class="text-lg font-semibold">Parent/Guardian Emergency Contact</h2>
        <p class="text-sm text-sky-100/85 mt-2 [text-align:justify]">
          Please provide your Parent/Guardian information so GateWatch can use it for emergency contact and registration support.
        </p>

        <form method="POST" class="mt-5 space-y-4">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="complete" />
          <input type="hidden" name="accepted_terms" id="accepted_terms" value="0" />

          <div>
            <label class="block text-sm font-medium text-sky-50">Parent/Guardian Full Name</label>
            <input name="guardian_full_name" type="text" required placeholder="e.g., Juan Dela Cruz"
              class="mt-1 w-full rounded-xl border border-white/25 bg-white/10 px-4 py-3 text-white placeholder:text-sky-100/50 focus:outline-none focus:ring-2 focus:ring-sky-300/60" />
          </div>

          <div>
            <label class="block text-sm font-medium text-sky-50">Parent/Guardian Email Address</label>
            <input name="guardian_email" type="email" required placeholder="e.g., guardian@example.com"
              class="mt-1 w-full rounded-xl border border-white/25 bg-white/10 px-4 py-3 text-white placeholder:text-sky-100/50 focus:outline-none focus:ring-2 focus:ring-sky-300/60" />
          </div>

          <div>
            <label class="block text-sm font-medium text-sky-50">Parent/Guardian Contact Number</label>
            <input name="guardian_contact_number" type="tel" required placeholder="e.g., 09XXXXXXXXX"
              class="mt-1 w-full rounded-xl border border-white/25 bg-white/10 px-4 py-3 text-white placeholder:text-sky-100/50 focus:outline-none focus:ring-2 focus:ring-sky-300/60" />
          </div>

          <div class="flex gap-3 flex-wrap pt-2">
            <button type="button" id="btn-back" class="rounded-full border border-white/25 bg-white/10 px-5 py-2.5 text-sm font-semibold text-white hover:bg-white/15 transition">Back</button>
            <button type="submit" class="rounded-full bg-emerald-400/90 hover:bg-emerald-400 px-6 py-2.5 text-sm font-semibold text-slate-900 transition">Submit & Create Account</button>
          </div>
        </form>
      </div>

      <p class="mt-6 text-xs text-sky-100/70 [text-align:justify]">
        After submission, your account will be <strong>pending verification</strong> by the Student Services Office.
      </p>
    </section>
  </main>

  <script>
    const accept = document.getElementById('accept');
    const btnContinue = document.getElementById('btn-continue');
    const stepTerms = document.getElementById('step-terms');
    const stepGuardian = document.getElementById('step-guardian');
    const acceptedInput = document.getElementById('accepted_terms');
    const btnBack = document.getElementById('btn-back');

    function syncContinueState() {
      btnContinue.disabled = !accept.checked;
    }

    accept.addEventListener('change', syncContinueState);
    syncContinueState();

    btnContinue.addEventListener('click', () => {
      if (!accept.checked) return;
      acceptedInput.value = '1';
      stepTerms.classList.add('hidden');
      stepGuardian.classList.remove('hidden');
      stepGuardian.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    btnBack.addEventListener('click', () => {
      stepGuardian.classList.add('hidden');
      stepTerms.classList.remove('hidden');
      acceptedInput.value = '0';
      stepTerms.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  </script>
</body>
</html>
