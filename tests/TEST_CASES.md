# Palmas Elite Gym — Test Cases (Priority 13)

> **How to use:** For each test, follow the steps, record the actual result, and mark PASS ✅ or FAIL ❌.
> Run the automated API tests via: `C:\xam\php\php.exe tests\api_test_runner.php`

---

## 1. Authentication (Admin/Staff Login)

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| A-01 | Valid login | POST valid username + password to `login.php` | Redirects to dashboard, session set | ⬜ |
| A-02 | Wrong password | POST valid username + wrong password | Error: "Invalid credentials", no session | ⬜ |
| A-03 | Empty fields | POST empty username/password | Error: "Fields required" | ⬜ |
| A-04 | SQL injection in username | POST `' OR '1'='1` as username | Login fails, no session | ⬜ |
| A-05 | Brute force (multiple) | POST wrong password 10 times rapidly | Login fails each time, no lockout crash | ⬜ |
| A-06 | Access dashboard without session | Visit `index.php` without logging in | Redirected to `login.php` | ⬜ |
| A-07 | Session hijack (tampered cookie) | Manually edit `PHPSESSID` cookie | Redirected to `login.php` | ⬜ |
| A-08 | Logout | Click logout | Session destroyed, redirected to login | ⬜ |

---

## 2. Authorization

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| B-01 | Staff access admin-only page | Login as Staff, visit `backup.php` | Redirected/Access denied | ⬜ |
| B-02 | Non-admin access settings | Login as Staff, visit `settings.php` | Redirected/Access denied | ⬜ |
| B-03 | Unauthenticated API call | Call `api/member_dashboard.php` with no token | `401 Unauthorized` JSON response | ⬜ |
| B-04 | Expired API token | Call API with manually expired token | `401 Unauthorized` JSON response | ⬜ |
| B-05 | Member accessing admin panel | Attempt to access `index.php` as member (no admin session) | Redirected to `login.php` | ⬜ |

---

## 3. Member Management

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| C-01 | Add member (valid data) | Fill all required fields in `add-member.php`, submit | Member created, success message shown | ⬜ |
| C-02 | Add member (missing name) | Submit with blank full_name | Validation error shown, no DB insert | ⬜ |
| C-03 | Add member (duplicate email) | Submit with existing email | Error shown, no duplicate created | ⬜ |
| C-04 | XSS in member name | Enter `<script>alert(1)</script>` as name | Displayed as plain text, script does not execute | ⬜ |
| C-05 | Edit member (valid data) | Change contact number in `edit-member.php` | Updated in DB, success message | ⬜ |
| C-06 | Upload invalid file type | Upload `.exe` or `.php` as profile photo | Upload rejected, error shown | ⬜ |
| C-07 | Upload oversized image | Upload >5MB image | Upload rejected, error shown | ⬜ |
| C-08 | View archived member | Archive a member, view their profile | Status shows "Archived", actions limited | ⬜ |

---

## 4. Membership Plans

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| D-01 | Create plan (valid) | Add plan with name, price, duration | Plan created and listed | ⬜ |
| D-02 | Create plan (negative price) | Submit plan with price = -100 | Validation error, no insert | ⬜ |
| D-03 | Create plan (zero duration) | Submit plan with 0 months duration | Validation error, no insert | ⬜ |
| D-04 | Delete plan with active subscriptions | Delete a plan that has members subscribed | Error shown, plan NOT deleted | ⬜ |

---

## 5. Subscriptions

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| E-01 | Renew member (valid plan) | Select active plan, submit renewal via `renew-member.php` | Subscription created, expiry updated | ⬜ |
| E-02 | Renew with no plan selected | Submit renewal without selecting a plan | Validation error, no DB insert | ⬜ |
| E-03 | Check expiry date calculation | Renew 1-month plan | Expiry = today + 30 days | ⬜ |

---

## 6. Payments

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| F-01 | Record payment (valid) | Add payment via `payments.php` | Payment recorded, shown in list | ⬜ |
| F-02 | Record payment (negative amount) | Submit amount = -500 | Validation error, no insert | ⬜ |
| F-03 | Record payment (zero amount) | Submit amount = 0 | Validation error, no insert | ⬜ |
| F-04 | Payment report accuracy | Add 3 payments of ₱500 each | Total shows ₱1,500 in reports | ⬜ |

---

## 7. Attendance

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| G-01 | Check-in (valid member) | POST valid `membership_id` to `log_attendance.php` | `check-in` success response, record created | ⬜ |
| G-02 | Check-in again (double scan) | POST same ID immediately after check-in | Blocked by 60-second debounce message | ⬜ |
| G-03 | Check-out (scan after 60s) | POST same ID after 60 seconds | `check-out` success, `time_out` set | ⬜ |
| G-04 | Invalid member ID | POST non-existent membership_id | Error: "Invalid Member ID" | ⬜ |
| G-05 | Expired member | POST membership_id of expired member | Error: "Membership Expired" | ⬜ |
| G-06 | Empty membership_id | POST empty string | Error: "Empty ID provided" | ⬜ |

---

## 8. QR Code Security

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| H-01 | Valid QR token | Generate token from `get_qr_token.php`, scan immediately | Check-in succeeds | ⬜ |
| H-02 | Expired QR token | Use token older than 30 seconds | Error: "QR Code has expired" | ⬜ |
| H-03 | Modified QR token | Change one character in the signature segment | Error: "Invalid QR Code security signature" | ⬜ |
| H-04 | Wrong member | Replace membership_id in token with another member's ID | Signature mismatch → rejected | ⬜ |
| H-05 | Invalid format | POST `abc:def` (only 2 parts) | Error: "Invalid QR Code format" | ⬜ |
| H-06 | Replay attack | Scan valid token twice within 60 seconds | Second scan blocked by debounce | ⬜ |
| H-07 | QR for expired member | Generate token for expired member, scan | Error: "Membership Expired" | ⬜ |

---

## 9. Renewal Requests

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| I-01 | Submit renewal request (mobile) | POST to `api/member_renew.php` with valid token + plan | Request created, pending status | ⬜ |
| I-02 | Submit renewal (no plan_id) | POST without plan_id | Error: plan required | ⬜ |
| I-03 | Approve renewal request | Admin approves via `renewal-requests.php` | Subscription created, member status → Active | ⬜ |
| I-04 | Reject renewal request | Admin rejects | Request status → Rejected, no subscription created | ⬜ |
| I-05 | Duplicate pending request | Submit 2nd request while one is Pending | Error: existing pending request | ⬜ |

---

## 10. Notifications

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| J-01 | Send manual reminder | Click "Send Reminder" for a member | Success toast shown, notification logged | ⬜ |
| J-02 | Send reminder (invalid member) | POST with non-existent member_id | `404` / error response | ⬜ |
| J-03 | View notifications list | Visit `notifications.php` | List renders, XSS-safe output | ⬜ |
| J-04 | Mark notification read | Click mark-as-read | Status updated, UI reflects change | ⬜ |

---

## 11. Email

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| K-01 | Password reset email | Submit forgot password for valid email | Email sent, link received | ⬜ |
| K-02 | Password reset (invalid email) | Submit non-existent email | Generic "if email exists" message shown (no leak) | ⬜ |
| K-03 | Reset link expiry | Use reset link after 1 hour | Error: "Link has expired" | ⬜ |
| K-04 | Reset link reuse | Use reset link a second time | Error: "Link already used" or "Invalid token" | ⬜ |

---

## 12. Reports

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| L-01 | Revenue report renders | Visit `reports.php` | Charts/tables load without errors | ⬜ |
| L-02 | Date filter | Filter reports by custom date range | Data changes to match selected range | ⬜ |
| L-03 | Export report | Click export/print | Report exports without PHP errors visible | ⬜ |
| L-04 | Empty data period | Filter for a period with no data | Shows empty state, no PHP warnings | ⬜ |

---

## 13. Backup & Recovery

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| M-01 | Generate backup | Click "Generate Backup" in `backup.php` | `.sql` file created in `/backups/`, success message | ⬜ |
| M-02 | Download backup | Click download on a backup | `.sql` file downloads to browser | ⬜ |
| M-03 | Delete backup | Click delete | File removed from `/backups/`, success message | ⬜ |
| M-04 | Path traversal attack | POST `file=../../config/env.php` | Blocked by `basename()`, no access | ⬜ |
| M-05 | Restore test | Restore a backup (test DB) | `gym_test` DB restored correctly | ✅ (Verified in Priority 9) |
| M-06 | Unauthorized backup access | Access `backup.php` as Staff (non-admin) | Access denied | ⬜ |

---

## 14. Mobile API

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| N-01 | Mobile login (valid) | POST to `api/member_login.php` with valid credentials | `200` + token returned | ⬜ |
| N-02 | Mobile login (wrong password) | POST wrong password | `200` + `success: false`, generic error | ⬜ |
| N-03 | Mobile dashboard (valid token) | GET `api/member_dashboard.php` with Bearer token | Member data returned | ⬜ |
| N-04 | Mobile dashboard (no token) | GET without Authorization header | `401 Unauthorized` | ⬜ |
| N-05 | Mobile dashboard (expired token) | GET with expired token | `401 Unauthorized` | ⬜ |
| N-06 | Mobile renew (valid) | POST to `api/member_renew.php` with valid plan_id | Renewal request created | ⬜ |
| N-07 | Mobile renew (no auth) | POST without token | `401 Unauthorized` | ⬜ |
| N-08 | DB error response | Simulate DB failure | `500` + generic safe message (no SQL exposed) | ⬜ |

---

## 15. Security

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|----------------|--------|
| O-01 | SQL injection in login | POST `' OR 1=1 --` as password | Login fails, no data returned | ⬜ |
| O-02 | XSS in member name (stored) | Save `<script>alert(1)</script>` as name, view member | Rendered as plain text, no execution | ⬜ |
| O-03 | CSRF token missing | POST to `backup.php` without `csrf_token` | Request rejected | ⬜ |
| O-04 | Direct script execution in uploads | Upload `.php` file to `/uploads/` | Blocked by `.htaccess` | ⬜ |
| O-05 | Direct URL access to uploads script | Visit `uploads/test.php` directly | `403 Forbidden` | ⬜ |
| O-06 | Exception message leak | Trigger a DB error | Generic safe message shown, no SQL in response | ⬜ |
| O-07 | QR secret not hardcoded | Remove `QR_SECRET_KEY` from env.php, hit API | API fails with server error (not default secret) | ⬜ |
| O-08 | Session fixation | Note PHPSESSID before login, check after login | PHPSESSID changes after login | ⬜ |
