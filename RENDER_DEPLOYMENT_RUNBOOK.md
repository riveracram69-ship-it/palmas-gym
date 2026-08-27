# Palma's Elite Gym Management System — Render Deployment Runbook

> **Live Deployment Status:** Deployment prepared but not executed (awaiting operator infrastructure provisioning).
> Follow this sequential runbook to perform the live deployment.

---

## Architecture Overview

```text
                           USERS
                             │
               ┌─────────────┴─────────────┐
               │                           │
         Admin / Member Browser        Android App (Capacitor)
               │                           │
               ▼                           ▼
        Render Web Service            HTTPS API Endpoints
         (Apache + PHP 8.2)           (/gym/api/member_*.php)
               │                           │
               └─────────────┬─────────────┘
                             │
                             ▼
                    Managed MySQL Database
                   (PlanetScale / Aiven / Railway)
                             │
                             ▼
                    Cloud Backup Storage
                  (Cloudflare R2 / AWS S3)
```

---

## Phase 1: Initialize Git Repository

In `C:\xam\htdocs\gggym\gym` (or workspace root), run:

```bash
git init
git add .
git commit -m "feat: configure production Docker and Render deployment setup"
git branch -M main
git remote add origin https://github.com/YOUR_ORGANIZATION/palmas-gym.git
git push -u origin main
```

*Note: Verify that `.env`, `*.sql`, `uploads/members/*`, and `*.jks` are NOT staged (protected by `.gitignore`).*

---

## Phase 2: Provision Managed MySQL Database

Since Render does not offer a native MySQL database:

1. **Option A: PlanetScale (Recommended)**
   - Sign up at [planetscale.com](https://planetscale.com).
   - Create a database `gym_management`.
   - Create a password/connection string with MySQL compatibility.

2. **Option B: Aiven for MySQL**
   - Create a service at [aiven.io](https://aiven.io).
   - Select MySQL 8.0, Singapore region.
   - Obtain `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`.

3. **Option C: Railway MySQL**
   - Create a MySQL instance at [railway.app](https://railway.app).
   - Retrieve connection variables.

---

## Phase 3: Database Import & Verification

> [!CAUTION]
> **Human Confirmation Gate:** The file `gym_management.sql` contains sensitive data. Confirm whether you wish to perform a **full import (data migration)** or a **schema-only clean installation**.

### Step A: Import Database
Using the MySQL CLI:
```bash
# For full import (existing members & records):
mysql -h <DB_HOST> -P <DB_PORT> -u <DB_USER> -p <DB_NAME> < gym_management.sql

# Or using a GUI client (e.g. DBeaver, phpMyAdmin, HeidiSQL):
# Run gym_management.sql against the target database.
```

### Step B: Validate Schema
Verify all 11 core tables are present:
1. `activity_logs`
2. `attendance`
3. `auth_tokens`
4. `members`
5. `membership_plans`
6. `notifications`
7. `payments`
8. `renewal_requests`
9. `subscriptions`
10. `system_settings`
11. `users`

*(Note: `login_rate_limits` will auto-create on first authentication attempt via `rate_limiter.php`)*

---

## Phase 4: Deploy Web Service to Render

1. Log in to [render.com](https://dashboard.render.com).
2. Click **New +** → **Web Service**.
3. Connect your GitHub repository (`palmas-gym`).
4. Select **Docker** environment (Render will automatically detect `Dockerfile`).
5. Choose your region (e.g. `Singapore`).
6. Set the **Instance Type** (e.g. `Starter` or `Free`).
7. In the **Environment Variables** section, add:

| Key | Example / Description |
|---|---|
| `APP_ENV` | `production` |
| `APP_URL` | `https://palmas-gym-app.onrender.com` (your Render URL) |
| `DB_HOST` | `<your-database-host>` |
| `DB_PORT` | `3306` |
| `DB_NAME` | `gym_management` |
| `DB_USER` | `palmas_gym_user` |
| `DB_PASS` | `<your-database-password>` |
| `QR_SECRET_KEY` | Generate via `php -r "echo bin2hex(random_bytes(32));"` |
| `KIOSK_API_KEY` | Generate via `php -r "echo bin2hex(random_bytes(32));"` |
| `CRON_SECRET_KEY` | Generate via `php -r "echo bin2hex(random_bytes(32));"` |
| `SMTP_HOST` | `smtp.gmail.com` |
| `SMTP_PORT` | `587` |
| `SMTP_USER` | `palmaselitegym.system@gmail.com` |
| `SMTP_PASS` | `<Gmail App Password>` |
| `SMTP_FROM` | `palmaselitegym.system@gmail.com` |
| `SMTP_FROM_NAME` | `Palma's Elite Gym` |

8. Click **Create Web Service**.

---

## Phase 5: Verification & Smoke Testing

### 1. Health Check
Open: `https://<YOUR-RENDER-SERVICE>.onrender.com/api/ping.php`
Expected Response:
```json
{"status":"ok","app":"palmas_elite_gym","timestamp":1724800000}
```

### 2. Admin Portal Check
1. Navigate to: `https://<YOUR-RENDER-SERVICE>.onrender.com/login.php`
2. Test login with administrative credentials.
3. Test creating a new member, generating membership plan, recording payment.

### 3. Member Portal Check
1. Navigate to: `https://<YOUR-RENDER-SERVICE>.onrender.com/member/login.php`
2. Test member login and digital QR ID card pass generation.

### 4. Security Verification
1. Test accessing `https://<YOUR-RENDER-SERVICE>.onrender.com/config/db.php` → Expected `403 Forbidden`.
2. Test accessing `https://<YOUR-RENDER-SERVICE>.onrender.com/.env` → Expected `403 Forbidden`.
3. Test accessing `https://<YOUR-RENDER-SERVICE>.onrender.com/gym_management.sql` → Expected `403 Forbidden`.

---

## Phase 6: Automated Daily Maintenance (Cron)

Set up automated daily execution of `cron/daily_maintenance.php`:

### Option A: GitHub Actions (Recommended — Free)
1. Add Repository Secrets in your GitHub repo:
   - `APP_URL`: `https://<YOUR-RENDER-SERVICE>.onrender.com`
   - `CRON_SECRET_KEY`: `<value configured in Render>`
2. The workflow file `.github/workflows/cron.yml` will automatically trigger maintenance every day at 00:05 UTC.

### Option B: cron-job.org (Free External Webhook)
1. Create a free account at [cron-job.org](https://cron-job.org).
2. Add a new Cronjob targeting:
   `https://<YOUR-RENDER-SERVICE>.onrender.com/cron/daily_maintenance.php?key=YOUR_CRON_SECRET_KEY`
3. Set schedule to execute once daily at 00:05 AM.

---

## Phase 7: Android Capacitor App Production Release

### Step 1: Update API URL
In `mobile-app/www/index.html`, ensure `DEFAULT_PROD_API_URL` is set to:
`https://<YOUR-RENDER-SERVICE>.onrender.com/gym/api`

### Step 2: Build & Sync Web Assets
In `mobile-app/`, run:
```bash
npm run build
```

### Step 3: Production Signing
> [!CAUTION]
> **Production Android Keystore Gate:**
> Do NOT lose the generated keystore file or password. It is permanently required for all future app updates.

Generate keystore:
```bash
keytool -genkey -v -keystore palmas-release-key.jks -alias palmasgym -keyalg RSA -keysize 2048 -validity 10000
```

Create `mobile-app/android/key.properties`:
```properties
storePassword=YOUR_STORE_PASSWORD
keyPassword=YOUR_KEY_PASSWORD
keyAlias=palmasgym
storeFile=../../palmas-release-key.jks
```

Build Release APK:
```bash
cd mobile-app/android
./gradlew assembleRelease
```
Output APK location: `mobile-app/android/app/build/outputs/apk/release/app-release.apk`

---

## Rollback Protocol

| Failure Scenario | Rollback Procedure | Authorization |
|---|---|---|
| Failed deployment / 500 error | In Render dashboard → **Deploys** → Select previous deploy → **Rollback to this deploy** | Operator |
| Database migration error | Restore backup using `mysql -u ... < backup.sql` | Operator |
| Key rotation breakage | Revert `QR_SECRET_KEY` or `KIOSK_API_KEY` in Render environment variables | Operator |
| Mobile connection failure | Operator updates `DEFAULT_PROD_API_URL` or users enter server URL in mobile settings | Operator |
