# STAGE 0 — PROJECT INTAKE & SAFETY CHECK
## GGGym Management System — Architecture Report

> **Auditor:** Senior Lead Developer & Systems Architect (External QA)
> **Date:** 2026-09-08
> **Status:** AWAITING APPROVAL BEFORE STAGE 1

---

## 1. Architecture Map

### Request Lifecycle — Admin Panel

```
Browser Request
    |
    v
Apache/Nginx -> .htaccess (Security rules, HTTPS redirect toggle, auth header forwarder)
    |
    v
PHP Entry File (e.g., index.php, members.php, payments.php)
    |
    +- require includes/header.php
    |       +- require config/auth.php       -> Session start, CSRF token generation, require_login()
    |       +- require config/db.php         -> PDO connection via config/env.php constants
    |       +- require config/logger.php
    |       +- require config/settings.php
    |
    +- Business logic (inline PHP in the page file)
    |       +- Queries via $pdo->prepare() / execute()
    |
    +- HTML output (PHP template mixed with HTML)
            +- includes/sidebar.php
            +- includes/footer.php
```

### Request Lifecycle — Member REST API (Mobile App)

```
Mobile App (Capacitor WebView) -> HTTP POST/GET
    |
    v
api/*.php endpoint
    |
    +- header("Content-Type: application/json")
    +- header("Access-Control-Allow-Origin: *")    <- Wildcard CORS (noted as RISK R-02)
    |
    +- require config/db.php
    +- [OPTIONAL] require api/auth_middleware.php   -> Bearer token validation via auth_tokens table
    |
    +- Business logic
    |       +- PDO prepared statements
    |
    +- echo json_encode(["success" => ...])
```

### Configuration Chain

```
config/env.php
    +- Reads: /env (outside webroot), ./.env, or config/.env
    +- Defines: DB_*, SMTP_*, QR_SECRET_KEY, KIOSK_API_KEY, CRON_SECRET_KEY
    |           GOOGLE_CLIENT_ID, PAYMONGO_*, PAYMENT_MODE
    +- Fallbacks: Development defaults hardcoded in env.php
```

---

## 2. Feature Inventory

| Feature | Location | Status |
|:---|:---|:---|
| Admin Authentication | login.php, logout.php, config/auth.php | Functional |
| Admin Dashboard (real-time metrics) | index.php (~1,513 lines) | Functional |
| Member CRUD | add-member.php, edit-member.php, view-member.php, members.php | Functional |
| Pending Member Approvals | pending-registrations.php | Functional |
| QR Attendance Tracking | attendance.php, member/attendance.php | Functional |
| Membership Plans Management | plans.php | Functional |
| Payments (Cash/Digital) | payments.php, renew-member.php, renewal-requests.php | Functional |
| Payment Gateway (PayMongo) | config/payment.php, config/paymongo.php | Functional |
| Payment Webhook Handler | api/payment_webhook.php | Functional |
| Reports and Analytics | reports.php (~111 KB) | Functional |
| Notifications | notifications.php, config/notifications.php | Functional |
| Email Notifications (PHPMailer) | config/email.php | Functional |
| Activity Logs | activity-logs.php, config/logger.php | Functional |
| Backup and Restore | backup.php | Functional |
| System Settings | settings.php, config/settings.php | Functional |
| Member Web Portal | member/*.php (18 files) | Functional |
| QR Digital Pass / ID Card | member/id-card.php, member/get_qr_token.php | Functional |
| Google OAuth 2.0 | api/google_auth.php | Functional |
| Rate Limiting (brute-force defense) | config/rate_limiter.php | Functional |
| Mobile App (Capacitor/Android) | mobile-app/www/index.html (~4,265 lines) | Functional |
| APK Download and Distribution | download.php, downloads/palmas-elite-gym.apk | Functional |
| Cron Jobs (expiry notifications) | cron/daily_maintenance.php | Functional |

---

## 3. API Inventory

All endpoints are under /api/. All return application/json.

| Endpoint | Method | Auth Required | Purpose |
|:---|:---|:---|:---|
| ping.php | GET | None | Server health check |
| member_login.php | POST | None | Authenticate member, return Bearer token |
| member_register.php | POST | None | Self-registration by new member |
| member_setup_password.php | POST | None | First-time password setup |
| google_auth.php | POST | None | Google OAuth member authentication |
| setup_auth.php | POST | None | Account linking utility |
| member_dashboard.php | GET | Bearer | Member home data (name, subscription, QR) |
| member_profile.php | GET/POST | Bearer | Fetch / update member profile |
| check_status.php | GET | Bearer | Membership status check |
| get_plans.php | GET | None | List available membership plans |
| get_notifications.php | GET | Bearer | Fetch member notifications |
| mark_notifications_read.php | POST | Bearer | Mark notifications as read |
| create_payment_checkout.php | POST | Bearer | Initiate PayMongo checkout session |
| confirm_auto_payment.php | POST | Bearer | Confirm payment after checkout |
| demo_checkout.php | POST | Bearer | Simulate payment (demo/test mode) |
| payment_history.php | GET | Bearer | Member payment history |
| payment_detail.php | GET | Bearer | Single payment receipt details |
| member_renew.php | POST | Bearer | Submit renewal request |
| register_device.php | POST | Bearer | Register FCM push notification token |
| admin_dashboard_ajax.php | GET/POST | Session (Admin) | AJAX data for admin dashboard widgets |
| payment_webhook.php | POST | HMAC Signature | PayMongo payment events (server-to-server) |

---

## 4. Database ERD Summary

Engine: InnoDB with UTF8MB4 on all tables. Foreign keys defined on critical relationships.

### Tables (14 total)

| Table | Key Columns | FK Relationships |
|:---|:---|:---|
| users | id, name, email, password, role | Standalone (admin accounts) |
| members | id, membership_id, full_name, email, password_hash, account_status, status, google_id | Core entity |
| membership_plans | id, name, duration_months, duration_minutes, price, is_test_promo | Referenced by subscriptions, payments |
| subscriptions | id, member_id, plan_id, start_date, expiry_date | FK -> members, membership_plans |
| payments | id, member_id, subscription_id, amount, payment_method, reference_number, is_test | FK -> members, subscriptions |
| payment_transactions | id, member_id, plan_id, subscription_id, reference_code, status | FK -> members, membership_plans |
| attendance | id, member_id, date, time_in, time_out | FK -> members |
| auth_tokens | id, member_id, token, expires_at | FK -> members (ON DELETE CASCADE) |
| renewal_requests | id, member_id, plan_id, requested_at, status | FK -> members, plans |
| notifications | id, member_id, type, title, message, status | FK -> members |
| activity_logs | id, action, description, module, user_id, user_name | Audit trail (no FK) |
| login_rate_limits | id, identifier, ip_address, endpoint, failed_attempts, lockout_until | RISK: Schema mismatch |
| member_devices | id, member_id, device_token, device_type | FK -> members (ON DELETE CASCADE) |
| system_settings | id, setting_key, setting_value | App configuration key-value store |

### Core Relationship Flow

```
members <-- subscriptions --> membership_plans
members <-- payments
members <-- payment_transactions
members <-- attendance
members <-- auth_tokens
members <-- renewal_requests
members <-- notifications
members <-- member_devices
```

---

## 5. Dependency Inventory

### PHP Libraries (manual, no Composer)

| Library | Location | Purpose |
|:---|:---|:---|
| PHPMailer | libs/PHPMailer/ | SMTP email delivery |
| Custom PayMongo Gateway | config/paymongo.php | Payment processing wrapper |

### Frontend CDN Dependencies (Admin Panel)

| Dependency | Source | Version |
|:---|:---|:---|
| Font Awesome | cdnjs.cloudflare.com | 6.5.0 |
| Inter / Outfit / Playfair Display | fonts.googleapis.com | Latest |

### Mobile App Dependencies

| Dependency | Source | Version |
|:---|:---|:---|
| @ionic/core | cdn.jsdelivr.net | Latest |
| Font Awesome | cdnjs.cloudflare.com | 6.4.0 |
| QRCode.js | cdnjs.cloudflare.com | 1.0.0 |
| Google Sign-In (GSI) | accounts.google.com | Latest |
| Capacitor | (build-time, Android) | (build artifact) |

---

## 6. Known Risks

### CRITICAL (3 items)

**R-01 | config/env.php:92-98 | Weak Default Secret Keys**
QR_SECRET_KEY ("palmas_secret_key_987!_change_me_in_prod"), KIOSK_API_KEY ("kiosk_api_12345"),
and CRON_SECRET_KEY ("palmas_cron_secret_2026") are hardcoded as fallbacks.
Any deployment without a properly configured .env file is running with these toy keys.
The .env.example file shows "REPLACE_WITH_..." placeholders, confirming keys are often unset.

**R-02 | api/*.php (all 17 endpoints) | Wildcard CORS**
Every API endpoint sends "Access-Control-Allow-Origin: *".
This allows any website to make credentialed API calls using a victim's Bearer token.
This is required for Capacitor development but must be restricted to the production domain in live.

**R-03 | config/auth.php:10 | SameSite: Lax Session Cookie**
Admin session cookie uses SameSite=Lax. Cross-site GET-navigation still carries the session cookie.
While CSRF tokens are implemented, defense-in-depth would enforce SameSite=Strict.

### HIGH (4 items)

**R-04 | view-member.php:6 | Potential IDOR on Member Viewing**
$id = $_GET['id'] ?? 0 is used with no role-scope enforcement on the query.
Any authenticated staff member (not just admin) can access any member's full profile.
While PDO prevents injection, the authorization check is missing.

**R-05 | login_rate_limits table | DB Schema Mismatch**
SQL dump has schema: (id, ip_address, attempts, last_attempt_at).
config/rate_limiter.php expects: (id, identifier, ip_address, endpoint, failed_attempts, lockout_until, last_attempt_at, created_at).
Rate limiter auto-creates table at runtime using CREATE TABLE IF NOT EXISTS. If the old schema exists,
the CREATE TABLE IF NOT EXISTS silently does nothing and all rate limit queries FAIL silently
(caught by catch blocks). Production may have non-functional rate limiting.

**R-06 | config/payment.php:297 | Hardcoded Business Contact Info in Code**
'contact' => 'support@palmasgym.com | (02) 8123-4567' is hardcoded in the payment receipt builder.
Should be sourced from system_settings table or .env.

**R-07 | config/env.php:104-106 | Hardcoded SMTP From Address**
SMTP_USER and SMTP_FROM default to the real gym email address.
If .env SMTP_PASS is not set, the system silently fails to send emails with no error to the end user.

### MEDIUM (7 items)

**R-08** - No API versioning. Breaking changes will break installed mobile apps.
**R-09** - index.php is 1,513 lines / 72KB. All business logic in one file.
**R-10** - mobile-app/www/index.html is 4,265 lines / 183KB. Unmaintainable single file.
**R-11** - members.php uses two correlated subqueries per member row for plan_name and expiry_date (N+1 SQL equivalent).
**R-12** - includes/sidebar.php fires 2 extra COUNT(*) queries on every admin page load.
**R-13** - HTTPS redirect in .htaccess is commented out. Production should enforce HTTPS unconditionally.
**R-14** - header.php uses time() as CSS cache-buster. Defeats browser caching entirely on every page load.

### LOW / INFO (5 items)

**R-15** - 15+ migrate_*.php scripts in public webroot (blocked by .htaccess but should not exist in production).
**R-16** - seed_50_records.php in public webroot (blocked, but should not ship to production).
**R-17** - gym_management.sql in public webroot (blocked by .htaccess extension rule).
**R-18** - CSRF error response is plain text, not JSON or HTML. Context-unaware for API callers.
**R-19** - member/auth.php duplicates config/auth.php logic. Two divergent maintenance points.

---

## 7. Recommended Execution Plan

```
STAGE 1  Validation Report (full tabled audit)
STAGE 2  Critical & High Fixes (R-01 secrets, R-02 CORS, R-05 DB schema, R-04 IDOR)
STAGE 3  Database & API Hardening (schema alignment, CORS restriction, indexing)
STAGE 4  Web UI/UX (dashboard refactor, N+1 fix, HTTPS, CSS caching)
STAGE 5  Mobile App UX (network error handling, back button, splash)
STAGE 6  Accessibility, Performance & Polish
STAGE 7  Full Regression Test
STAGE 8  Production Readiness Review
```

HIGHEST PRIORITY BEFORE ANYTHING ELSE: Verify that the production .env has real cryptographic
secret keys — not the hardcoded fallback defaults in config/env.php.

---

## 8. EMERGENCY STOP — AWAITING APPROVAL

STAGE 0 IS COMPLETE. NO CODE CHANGES HAVE BEEN MADE.
This document is a read-only architectural survey only.

To continue, reply with:
  "Approved — proceed to Stage 1"

Or provide corrections to this report before proceeding.

---
Report by: External QA Team
Files reviewed: 40+ PHP files, SQL schema, .htaccess, .env, mobile HTML app (4,265 lines)
