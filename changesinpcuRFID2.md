# Security, Reliability, Usability & Authentication Review — Non‑destructive Recommendations (aligned to ISO/IEC 25010)

Date: 2025-12-06  
Repository: jeysidelima04-cpu/pcuRFID (review based on repository contents listing; results may be incomplete — view the repo at: https://github.com/jeysidelima04-cpu/pcuRFID/contents)

Note: I produced a focused, non-destructive set of recommendations and explicitly mapped them to ISO/IEC 25010 quality characteristics so changes preserve the existing application logic, database tables, button actions, and authentication behaviour. Follow the rollout and testing guidance below to ensure zero disruption.

---

## What I inspected
Files that informed this review (may be incomplete): auth.php, login.php, signup.php, db.php, validate.php, forgot_password.php, reset_password*.php, google_callback.php, complete_google_registration.php, verify_2fa.php, upload_profile_picture.php, profile.php, homepage.php, add_verification_system.php, password_resets.sql, database.sql, error.log, composer.phar, vendor/, config/, security/, and others.

Main immediate observations:
- Committed logs and binaries (error.log, composer.phar) and vendor/ contents.
- Multiple authentication and upload endpoints needing hardening.
- OAuth and 2FA flows present and require validation/controls.

---

## High-level, non‑destructive recommendations (short)
1. Remove logs/binaries from future commits and add them to .gitignore. Rotate secrets if leaked.
2. Use prepared statements (PDO/mysqli) instead of string-concatenated SQL; keep same DB schema and semantics.
3. Use password_hash() / password_verify() with on-login re-hash to avoid breaking existing accounts.
4. Harden password reset: use secure tokens (random_bytes), store hashed tokens, set expiry and single-use.
5. Harden sessions: session_regenerate_id(true), secure/httponly/samesite cookies, inactivity logout.
6. Harden file uploads: MIME checks, file size, random filenames, store outside web root or with restrictive ACL.
7. Add CSRF tokens to state-changing forms without changing endpoints (add hidden fields).
8. Escape user output (htmlspecialchars) at view layer to prevent XSS (no DB changes).
9. Validate OAuth callback state and verify ID tokens properly; never commit secrets.
10. Ensure 2FA uses standard TOTP; provide backup codes and rate limiting.

All recommendations above are designed to be non-destructive: they do not change table names, column names, button endpoints, or core auth flow semantics. Where schema changes might be desirable, I recommend backward-compatible migration steps first (add nullable columns, compatibility code, feature flags).

---

## ISO/IEC 25010 Mapping (recommendation → quality characteristic, acceptance criteria)

ISO/IEC 25010 defines quality characteristics such as Functional suitability, Performance efficiency, Compatibility, Usability, Reliability, Security, Maintainability, Portability. Below each characteristic I map the specific recommendations and provide acceptance criteria to ensure non-destructive implementation.

1. Functional suitability
   - Relevant recommendations:
     - Ensure authentication flows (login, signup, OAuth, password reset) continue to meet functional requirements.
     - Maintain existing endpoints and response semantics.
   - Acceptance criteria (non-destructive):
     - All core end-to-end flows (signup, login, Google login, password reset) succeed with the same inputs as before.
     - No endpoint URIs or expected request/response fields are changed without backward-compatible bridging.
     - Automated integration tests for each core flow pass.

2. Performance efficiency
   - Relevant recommendations:
     - Centralize DB access (PDO) and use efficient queries; avoid heavy synchronous logging on request path.
     - Avoid scanning whole file directories for uploads on each request.
   - Acceptance criteria:
     - Average response time for key pages (login, profile) does not degrade >10% after changes.
     - No increase in peak CPU or memory beyond acceptable thresholds (monitor in staging).
     - DB queries use prepared statements and keep existing indexes; no full-table scans are introduced.

3. Compatibility
   - Relevant recommendations:
     - Avoid changes that break clients, forms, or existing deployment config.
     - Keep same HTML form field names and endpoints; add CSRF tokens as additional optional fields.
   - Acceptance criteria:
     - Existing forms and automated clients continue to function unchanged (unless explicitly migrated).
     - Any configuration changes (env variables) are documented; default behavior remains backwards-compatible.

4. Usability
   - Relevant recommendations:
     - Improve form inline validation, provide friendly messages; keep same UI flows and buttons.
     - Provide image preview and clear error messages for uploads.
   - Acceptance criteria:
     - Visual layout and button actions are preserved; added UI improvements are unobtrusive.
     - End users notice improved error clarity without change to workflow steps.

5. Reliability
   - Relevant recommendations:
     - Add transactions for multi-step DB operations; centralize DB error handling.
     - Move logs outside repo and show friendly error pages for users.
   - Acceptance criteria:
     - Multi-step operations are atomic (rollback on failure) where applicable.
     - Error pages do not leak stack traces; logs stored securely.
     - System recovers from transient DB errors via retries where appropriate.

6. Security
   - Relevant recommendations:
     - SQL injection mitigations (prepared statements); XSS output encoding; CSRF tokens; session hardening; secure password storage; hardened file uploads; OAuth validation; rate limiting; do not commit secrets.
   - Acceptance criteria:
     - No raw SQL concatenation remains in auth-critical paths (login/signup/profile).
     - CSRF tokens are validated for all state-changing POSTs.
     - Session cookie flags set: Secure, HttpOnly, SameSite.
     - Passwords use password_hash(); password verification uses password_verify(); existing users preserved with on-login re-hash.
     - Uploads reject malicious files and preserve site integrity.
     - OAuth flows validate state parameter and token signatures.
     - Rate limiting blocks brute-force attempts in tests.

7. Maintainability
   - Relevant recommendations:
     - Centralize DB layer, add linters (PHPStan/Psalm), add unit/integration tests, add CI.
     - Remove vendor and binaries from repo; rely on composer.json/lock.
   - Acceptance criteria:
     - Static analyzer baseline is established and warnings fixed progressively.
     - New unit/integration tests cover auth flows and are run on CI for each PR.
     - Code is modularized so small changes do not ripple widely.

8. Portability
   - Relevant recommendations:
     - Use environment-based configuration (env vars/.env.example).
     - Keep codebase independent of platform-specific features.
   - Acceptance criteria:
     - Application can be configured with env vars only; no credentials in repo.
     - Deployment instructions updated and validated on target environments.

---

## Detailed non-destructive implementation guidance (minimum risk approach)

- Work flow & staging:
  - All changes are implemented in feature branches and deployed to staging that mirrors production.
  - Back up production DB before any deploy; take DB snapshots around schema changes.

- DB & authentication changes:
  - Use prepared statements for parameter binding without altering SQL semantics or table names.
  - For password hashing migration:
    - Implement on-login re-hash:
      - Attempt verify using new scheme; if fails, verify using legacy method; if legacy succeeds, re-hash with password_hash() and update same column.
    - This preserves authentication for all users and requires no immediate forced resets.
  - Avoid altering column names or removing fields. If new columns are needed, add them as nullable defaults.

- Sessions & cookies:
  - Add session_regenerate_id(true) after login and privilege changes.
  - Set cookie flags at runtime (ini_set or session_set_cookie_params) — this is configuration code, not database.

- CSRF tokens:
  - Add hidden token fields on forms and verify them server-side.
  - Maintain all existing form input names and endpoints; tokens add a hidden field only.

- File uploads:
  - Validate content-type and image headers (getimagesize or finfo), enforce size limits, sanitize filenames, use generated random names, and store uploads outside web root or in a folder served via a controlled script.
  - Keep same POST fields; only change server-side validation/handling.

- OAuth:
  - Validate "state" and use Google's token verification libraries.
  - Move client secrets to env vars; ensure the app still reads config from the same source (or provide fallback) during migration.

- Logging & secrets:
  - Remove error.log and composer.phar from future commits; add them to .gitignore.
  - Document required env variables in .env.example.
  - Rotate secrets if they were committed previously.

---

## Prioritized short checklist (first actions — non-destructive)
1. Remove error.log, composer.phar and vendor/ from repository history/future commits and add to .gitignore (no app logic changes).
2. Introduce a DB wrapper using PDO and refactor a single critical file (e.g., login.php) to use prepared statements; test thoroughly in staging.
3. Add on-login password re-hash for migrating to password_hash().
4. Add CSRF tokens to all state-changing forms; verify server-side.
5. Set secure cookie flags and call session_regenerate_id(true) at login.
6. Harden upload validation and storage (preserve API surface).
7. Add basic rate limiting for login/reset endpoints (app middleware or web server).
8. Enable static analysis, add unit/integration tests, and add a GitHub Actions CI workflow.

---

## Testing plan (to assert non‑destructive behavior)
- Unit tests:
  - DB wrapper functions, password verification and re-hash logic, token generation/validation.
- Integration tests:
  - Signup/login (including legacy hash path), password reset (generate → consume), OAuth callback (state + token verification), file upload (valid/invalid).
- Acceptance tests:
  - Manual or automated browser tests to verify forms, buttons, and flows operate identically from a user's perspective.
- Performance tests:
  - Basic benchmark of login/profile pages pre- and post-change to ensure acceptable performance.

---

## Rollback & emergency plan
- Have a tested rollback procedure that redeploys the prior release.
- Restore DB snapshot only if schema changes were performed and cannot be safely reversed.
- Use feature flags for major changes to allow quick disablement if problems occur.

---

## Example safe change candidates (I can produce patches)
- Convert login.php to PDO + prepared statements + on-login re-hash (no DB schema change).
- Add CSRF helper and add token to signup/login/profile forms (hidden field only).
- Create .gitignore and .env.example and show safe steps to remove composer.phar and vendor/ from future commits.

---

## Next steps I recommend (pick one)
- I can produce a concrete, non-destructive code patch for a single file, e.g. "login.php — migrate to PDO, prepared statements, and implement on-login re-hash." This change will be implemented to preserve existing DB schema and login semantics and include tests and staging instructions.
- Or I can produce a small CI workflow (GitHub Actions) to run PHPStan and PHPUnit and add a baseline test suite.

---

## Final assurance about non-destructiveness
- The guidance here intentionally avoids renaming or deleting DB tables/columns, changing endpoint URLs, or altering button/form names.
- Where schema changes might help long term, the guidance prescribes backward-compatible migration steps (nullable columns, compatibility code, staged rollout).
- Absolute assurance requires applying changes in staging and running the tests described above before production deployment.

---

If you want, tell me which specific file or area to produce the safe, non-destructive patch for first (for example, "login.php"), and I will prepare the patch, tests, and step-by-step deployment instructions that conform to the ISO/IEC 25010 mapping and the non-destructive rules above.