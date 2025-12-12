# pcuRFID2 — Feature Planning: Lost RFID handling, Parent Entry Notifications, Google-only Sign-in

Date: 2025-12-11  
Author: planning for pcuRFID2 project

---

This document defines a non-breaking, step-by-step plan (logic, UI/UX, and database) to implement three phases that follows the existing UI/UX designs:

- Phase 1: Admin toggle to mark a student's RFID as "lost" to prevent tampering with another student's violation limits if found and used by someone else.
- Phase 2: Use existing PHPMailer integration to send an email notification to a student's parent/guardian when the student enters school; the email should include date and time of entry.
- Phase 3: Remove manual account creation / signup and manual email/password inputs so the system relies solely on the existing Google Sign-In. Keep the rest of the auth logic intact and non-breaking.

This plan emphasizes backwards-compatible, incremental changes, clear acceptance criteria, testing, and rollback steps.

---

Contents
- High-level goals and assumptions
- Cross-cutting constraints & non-breaking principles
- Phase 1 — Lost RFID (detailed logic, DB, UI/UX, tests)
- Phase 2 — Email notification on entry (detailed logic, DB, email template, UI/UX, tests)
- Phase 3 — Google-only login (detailed logic, DB changes/migration strategy, UI/UX, tests)
- Deployment, QA, monitoring, rollback
- Acceptance criteria and estimated effort

---

High-level goals and assumptions
- Goal: implement features without breaking existing flows for staff and students.
- Preserve existing data and behavior unless explicitly changed.
- Add non-destructive DB migrations (new columns/tables rather than destructive changes).
- Provide admin controls for new features (enable/disable).
- Use the repository's current PHP codebase and PHPMailer usage patterns for integration consistency.
- The system already has: students table, rfid scan logging, PHPMailer configured, Google Sign-In integrated.
- Student records include parent/guardian contact info (if not, plan includes adding it).

Cross-cutting constraints & non-breaking principles
- Backwards compatible: do not delete or rename DB columns used by current logic; add new columns/tables.
- Feature flags: admin toggles to enable/disable new behaviors.
- Safety: all checks must be server-side (not only UI) to prevent tampering.
- Logging & audit: every change should be logged for debugging and rollback.
- Tests: unit tests for logic and integration tests for end-to-end scenarios.
- Privacy & consent: email notifications should be opt-in and have admin control; respect COPPA/FERPA/region laws if required.

---

PHASE 1 — Admin "Lost RFID" handling

Objective
- Give admin the ability to mark a student's registered RFID as "lost".
- When an RFID is marked lost, scans from that RFID must not affect (increase/decrease/tamper) any student's violation counts or violation-limited actions.

Key ideas
- Add a boolean flag and timestamp to the students table (or add a separate rfid_assignments table) to record lost status.
- On any operation that applies violations or counts resulting from a scan (e.g., increments/decrements), check:
  - Is incoming tag equal to the student's registered tag? AND
  - Is that student's rfid_lost = false? If rfid_lost = true, ignore violation increments, only log the scan for audit.
- If a lost tag is used by another student (i.e., read at a different student context), do NOT allow that read to affect any student's violation counters.
- Admin UI: add toggle + timestamp and reason field and an "Unmark" action.

Database changes (non-destructive)
- Option A (simpler, minimal change): add columns to students table.
  - ALTER TABLE students ADD COLUMN rfid_lost TINYINT(1) NOT NULL DEFAULT 0;
  - ALTER TABLE students ADD COLUMN rfid_lost_at DATETIME NULL;
  - ALTER TABLE students ADD COLUMN rfid_lost_reason VARCHAR(255) NULL;
- Option B (recommended for audit/history): create rfid_assignments table (keeps history).
  - CREATE TABLE rfid_assignments (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      student_id BIGINT NOT NULL,
      rfid_uid VARCHAR(255) NOT NULL,
      assigned_at DATETIME NOT NULL,
      revoked_at DATETIME NULL,
      lost TINYINT(1) NOT NULL DEFAULT 0,
      lost_at DATETIME NULL,
      lost_reason VARCHAR(255) NULL,
      INDEX(student_id),
      INDEX(rfid_uid)
    );
- Choose Option A for low effort; Option B if you want full history.

API / backend changes
- Add a single function helper, e.g., is_rfid_active_for_student($studentId, $rfidUID):
  - If using students.rfid column: return ($rfidUID === $student->rfid && !$student->rfid_lost).
  - If using rfid_assignments: check the latest non-revoked assignment for student and that lost=0.
- Update all code paths where scans can modify student violation counts:
  - Before applying any change, call is_rfid_active_for_student() and only apply if true.
  - If false, log the scan as "ignored: lost rfid" or "ignored: unassigned rfid".
- Keep existing logs: add a field to scans log like ignored_reason VARCHAR(255).

UI / UX for admin
- Location: Admin → Students → Student profile → RFID section.
- Show current RFID UID, and a "Mark as Lost" toggle with modal:
  - Modal asks for confirmation, optional reason textarea, and records timestamp.
  - After marking lost: show red badge "RFID marked as LOST at <datetime> — reason: <text>" and an "Unmark" button.
- In student list view: show an icon/badge for students with lost RFID.
- In scans dashboard: when a scan is ignored because lost, show a row with "Ignored — RFID is marked lost" and link to student profile.

Edge cases & non-breaking handling
- If a lost RFID gets scanned and it is still physically assigned to no student: log but do not alter violation counters.
- Admin can unmark lost — no automatic merging of any events should be retroactively applied; consider the audit trail only.
- Don't delete or change stored violations on marking lost/unlost.

Migration sample SQL (students table approach)
- BEGIN TRANSACTION;
- ALTER TABLE students ADD COLUMN rfid_lost TINYINT(1) NOT NULL DEFAULT 0;
- ALTER TABLE students ADD COLUMN rfid_lost_at DATETIME NULL;
- ALTER TABLE students ADD COLUMN rfid_lost_reason VARCHAR(255) NULL;
- COMMIT;

Acceptance criteria (Phase 1)
- Admin can mark/unmark lost with reason and timestamp.
- Scans from a lost RFID do not alter any student's violation counts.
- Ignored scans are logged for auditing.
- No tests or existing functionality break.

Tests
- Unit: is_rfid_active_for_student() returns false when rfid_lost=1 and true otherwise.
- Integration: simulate scan from lost RFID — ensure violation counters unchanged and scan logged with ignored_reason.
- UI: admin mark/unmark flow works and shows badges.

---

PHASE 2 — Parent/Guardian Email Notification on Entry

Objective
- When a student's entry scan is recorded, send an email notification to the parent/guardian using the existing PHPMailer implementation. Include date and time of entry in the email.

Key ideas
- Reuse existing PHPMailer configuration and functions for consistency.
- Add admin/global toggle: enable/disable parental notifications.
- Add per-student opt-in/opt-out or require guardian email present.
- Rate-limit duplicate notifications (e.g., don't spam for multiple scans within a short window).
- Log notification sending and failures in a notification_log table.

Database changes
- Ensure student records have guardian_email and guardian_name columns; if not present, add them:
  - ALTER TABLE students ADD COLUMN guardian_name VARCHAR(255) NULL;
  - ALTER TABLE students ADD COLUMN guardian_email VARCHAR(255) NULL;
  - ALTER TABLE students ADD COLUMN notify_guardian TINYINT(1) NOT NULL DEFAULT 0;
- Create a notifications table:
  - CREATE TABLE notification_logs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      student_id BIGINT NOT NULL,
      guardian_email VARCHAR(255) NOT NULL,
      event_type VARCHAR(50) NOT NULL, -- e.g., 'ENTRY'
      event_time DATETIME NOT NULL,
      sent_at DATETIME NULL,
      status VARCHAR(50) NULL,
      error TEXT NULL
    );

Backend integration
- Where entry is recorded (scan processing pipeline), after the scan is accepted and mapped to a student:
  1. Check admin/global toggle: config('notifications.guardian_entry_enabled') === true.
  2. Check student.notify_guardian === true AND guardian_email is not null/empty AND valid email.
  3. Check notification rate-limiter: last notification for this student of type ENTRY was not within X minutes (configurable e.g., 10m).
  4. Compose message with student name, date/time (ISO and human-readable), location if available, and a short message.
  5. Use existing PHPMailer sending flow (reuse wrappers). Record result in notification_logs (sent_at, status, error).
  6. If send fails, set status='FAILED', save PHPMailer error message, consider retry policy (exponential backoff by background job).

Email template (plain + HTML)
- Subject: "[SchoolName] Student Entry Alert — {STUDENT_NAME} at {TIME}"
- HTML body:
  - Greeting: "Dear {GUARDIAN_NAME},"
  - Body: "This is an automated alert to inform you that {STUDENT_NAME} entered the school premises on {DATE} at {TIME}."
  - Additional info: last known location, device/station name if available.
  - Footer: "If you believe this is an error, contact the school office" + privacy notice + unsubscribe/opt-out instructions (if required).
- Plain text fallback: same content in text form.

Sample PHP snippet using PHPMailer (conceptual)
- (Use existing PHPMailer helper; adapt to current codebase)
- $mail = new PHPMailer(true);
- $mail->setFrom(SITE_FROM_EMAIL, SITE_FROM_NAME);
- $mail->addAddress($guardian_email, $guardian_name);
- $mail->Subject = $subject;
- $mail->isHTML(true);
- $mail->Body = $html_body;
- $mail->AltBody = $text_body;
- $mail->send();

UI / UX changes
- Admin Panel:
  - Global toggle "Enable Guardian Entry Notifications".
  - Default rate-limit setting (e.g., 10 minutes).
  - Monitoring view: last N notifications, delivery status.
- Student profile:
  - Show guardian_name and guardian_email.
  - Checkbox "Send entry notifications to guardian" (notify_guardian).
  - Quick-send button (manual test email to guardian).
- Scan dashboard:
  - Show notification status for entry events (sent/failed/pending).

Edge cases & privacy
- If guardian email invalid or bounce, notify admin and disable notify_guardian for that student after N failures (configurable).
- Provide an opt-out/unsubscribe mechanism if legally required; if not required, ensure admins can toggle per-student notifications.
- Ensure timestamps are timezone-aware; save events in UTC and render in school local timezone.

Acceptance criteria (Phase 2)
- When student entry is recorded and guardian notifications are enabled, an email is attempted using PHPMailer.
- The notification includes date and time of entry.
- Notifications are rate-limited and logged.
- Admins can enable/disable globally and per student.

Tests
- Unit: notification eligibility logic (guardian email present, enabled toggles).
- Integration: simulate an entry and assert that notification_logs contains a success entry and PHPMailer called (or mocked).
- UI: admin toggle and per-student checkbox behave as expected.

---

PHASE 3 — Remove signup.php and manual email/password inputs; rely solely on Google Sign-In

Objective
- Remove/create flows so that user signup is performed only via Google Sign-In.
- Remove signup page and manual email/password input boxes and logic from login.php.
- Remove Forgot Password (if present) — user password flows not required for Google-only login.
- Ensure existing authenticated logic and sessions remain intact; do not break admin flows.

Important safety notes
- There may already be students and staff that have manually created accounts using email/password. We must not break their access; plan for account linking based on email addresses when they first use Google Sign-In.
- Keep password columns in DB (for rollback and for legacy users) — do not delete until migration and link verification complete.

Implementation plan & migration strategy
1. Inventory:
   - Identify the files to remove/modify: signup.php, any include files or form handlers for manual signup, login.php (form inputs), reset password endpoints (forgot password), and client-side JS tied to manual signup.
   - Identify server-side auth logic: functions that validate email/password (e.g., authenticate_by_password()).

2. Non-destructive approach:
   - Remove or disable the UI for manual signup/login but leave server-side password auth code available but unreachable from the main UI.
   - Optionally keep a hidden admin-only route to allow emergency login by password for migration/rollback.
   - Keep database password fields intact and do not bulk-delete records.

3. Seamless linking:
   - Modify Google Sign-In callback logic:
     - When a user authenticates with Google, check for existing user by google_id.
     - If none, check for existing user by email (case-insensitive).
       - If found and the found user had a manual password, link google_id to existing user (update users.google_id and set users.google_linked_at).
       - If found but the email is already attached to some other google account, alert admin (conflict).
     - If no existing user by email, create a new user record as before but mark provider='google'.
   - This preserves existing accounts and ensures previously registered users can continue via Google.

4. UI changes
   - login.php should display only:
     - "Sign in with Google" button (using existing Google Sign-In integration).
     - One main button for support/help (if requested: e.g., "Need Help? Contact Admin") — user requested "Also remove the forgot password and create one button from the login.php." Interpretation implemented: remove "Forgot password" link and the manual sign-in form; left is a single Google sign-in and an optional single support/contact button.
   - Remove signup.php page(s) from routes, nav, and links.
   - Keep admin panel for user management (create accounts manually if needed).
   - Onboarding: Add an admin-facing migration tool to notify admins of users without google_id that need to be linked or to invite parents to sign in with Google.

5. Edge cases & migration
   - Users who do not have Google accounts and rely on email/password: Admin must be able to:
     - Export a list of those users.
     - Contact and assist them to create Google accounts, or temporarily re-enable manual login (hidden admin toggle).
   - If the system uses email verification on sign-up, ensure Google created accounts are set as verified.
   - If login is used by staff/admins who must keep passwords, create a role-based exception or allow admin-only password login toggles.

DB changes
- Prefer none for Phase 3. However, add tracking columns to users/students table to record the sign-in provider and linked timestamp:
  - ALTER TABLE users ADD COLUMN auth_provider VARCHAR(50) DEFAULT 'local'; -- 'local' or 'google'
  - ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL;
  - ALTER TABLE users ADD COLUMN google_linked_at DATETIME NULL;
- When a Google sign-in is successful, set auth_provider='google' and google_id accordingly.

Code changes (high level)
- Remove files/routes:
  - Remove signup.php, signup handler endpoints or redirect them to login.php with a message.
  - Clean up header/footer links pointing to signup or forgot password.
- Modify login.php:
  - Remove input fields: <input type="email"> and <input type="password"> and the submit button for manual login.
  - Keep the existing Google Sign-In button and callback.
  - Remove "Forgot password" link.
  - Add a single support/help button (optional) — labelled "Need help? Contact Admin" that opens a modal or mailto:admin@school or points to support page.
- Modify server-side login handler:
  - Ensure that login endpoint that handles POST email/password is either removed or returns 410/generic message if called, and logs attempts. Prefer to respond with 403 and human message like "Password login is disabled; use Sign in with Google."
  - Ensure Google callback properly handles new and existing users per linking strategy.

UI copy suggestions
- Login page heading: "Sign in to [School Name]".
- Button: Big primary "Sign in with Google" (Google branding).
- Secondary: "Need help? Contact Admin" (single button as requested).
- Error messages: "If you previously signed up with an email and password, sign in using Sign in with Google — we'll link your account automatically if the email matches. If you need assistance, contact the admin."

Acceptance criteria (Phase 3)
- signup.php removed (links redirected) and manual signup and login UI removed.
- login.php only shows "Sign in with Google" plus one support button.
- Existing users with matching emails can sign in by Google (accounts linked).
- No login flows for manual password remain accessible from main UI.
- Admin has fallback or migration pathway to support non-Google users.

Tests
- Link flow: simulate Google sign-in where an existing user with the same email exists; assert the google_id saved and session created.
- New Google user: user is created with auth_provider='google'.
- Manual login attempts return a polite message and are logged.
- UI: login.php has no email/password fields; only Google button and a single support button.

---

Deployment, QA, monitoring & rollback

Stepwise deployment (recommended)
1. Phase 1: Deploy DB migration + admin UI toggles (staging). QA. Deploy to production.
2. Phase 2: Deploy DB additions and notification flow (staging). Test with mock emails and PHPMailer. After QA, enable in production with a small pilot set of student accounts.
3. Phase 3: Deploy code changes to remove signup and manual login UI in staging. Keep password auth code reachable through admin-only toggle (hidden config) for emergency rollback. After QA and sufficient communication to users, enable in production.

QA checklist
- Run DB migration backup before applying.
- Smoke test login flows (Google sign-in both for new and existing users).
- Test marking RFID lost and scanning from that tag — ensure no violation modifications.
- Validate emails are sent, content correct, and logged.
- Validate rate-limits and failure handling.
- Check logs for unexpected exceptions.

Monitoring
- Add logs and small admin dashboard for:
  - Ignored scans due to lost RFID.
  - Notification-send successes/failures.
  - Google sign-in linking conflicts.
- Configure alerts for large failure counts for PHPMailer or spikes in ignored scans (possible misuse).

Rollback plan
- Keep DB backups (dump before applying migrations).
- Since migrations are additive (new columns/tables), rollback is typically:
  - Revert code to previous commit.
  - If needed, remove added columns (carefully) after ensuring no data is critical.
- If Phase 3 causes signin issues, re-enable manual login via admin-only config toggle; restore UI from previous commit.

Security & privacy considerations
- Emails: do not expose other students' info in notification.
- Rate-limit notifications to avoid information leakage.
- Validate and sanitize all admin inputs for marking lost (reason text).
- Ensure PHPMailer credentials remain secure and environment-driven (config file / .env) and not checked into repo.

Estimated effort (rough)
- Phase 1: 1–2 dev days + 0.5 day QA.
- Phase 2: 2–3 dev days + 1 day QA + email provider testing.
- Phase 3: 1–2 dev days + 1 day QA + communication to users/admins.

Deliverables per phase
- Phase 1:
  - DB migration script + rollback notes.
  - Admin UI changes (mark lost).
  - Updated scan processing logic.
  - Tests and monitoring alerts.
- Phase 2:
  - DB columns for guardian info + notification_logs table.
  - Notification sending code integrated with existing PHPMailer logic.
  - Admin toggles and notification logs UI.
  - Email templates (HTML + plain).
  - Tests and rate-limiting.
- Phase 3:
  - Code updates removing signup & manual login UI.
  - Google sign-in linking flow for pre-existing users.
  - Admin fallback toggle for re-enabling password auth (hidden).
  - Documentation updates and user communication plan.

Implementation checklist (developer action items)
- [ ] Create DB migration file(s) for Phase 1 and Phase 2 (non-destructive).
- [ ] Implement is_rfid_active_for_student() helper and call in all violation-modifying paths.
- [ ] Add admin UI elements and endpoints to mark/unmark lost RFID.
- [ ] Add notification_logs table and student guardian columns; admin toggles.
- [ ] Implement notification flow integrated with PHPMailer and logging.
- [ ] Add rate-limit logic for notifications.
- [ ] Update Google callback to implement account linking and add auth_provider/google_id columns if not present.
- [ ] Remove signup.php and manual login form inputs from login.php; ensure only Google button and one support button remain.
- [ ] Add QA tests and integration tests for each major behavior.
- [ ] Add admin docs and user comms for the Google-only sign-in change.

Communication & user migration note
- Before removing signup / password login in production, notify users and parents, provide instructions to sign-in with Google, and provide admin support.
- Provide a one-time migration window where admins can help link accounts or reenable manual login temporarily if needed.

---

If you want, I can:
- produce the exact SQL migration files (for students table and notification_logs),
- produce PHP code snippets (exact functions, hooks) adjusted to your current code layout (point me to the files handling scans, authentication, and PHPMailer wrapper),
- generate the exact HTML/CSS snippet for login.php containing only the Google sign-in button and one support button,
- or create a PR with these changes.

STRICTLY DOUBLE AND TRIPLE CHECK ALL THE CONNECTION OF THE DATABASE AND THE LOGIC OF THE WHOLE PROJECT AFTER YOU MADE THESE CHANGES, MAKE SURE THAT IT WORKS AS IT WE'RE BEFORE! 

STRICTLY DONT MAKE MISTAKES.