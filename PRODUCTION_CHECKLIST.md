# Palmas Elite Gym — Production Deployment Checklist

Before deploying to a live/production environment, the following steps MUST be completed.

## Mobile App (Capacitor)

### 1. Enable HTTPS
- [ ] Set up SSL/TLS certificate on your production web server (e.g., via Let's Encrypt or a commercial cert).
- [ ] Update the API URL in `mobile-app/www/index.html`:
  ```js
  // Change from:
  let API_URL = `http://192.168.100.11/gym/api`;
  // Change to:
  let API_URL = `https://yourdomain.com/api`;
  ```
- [ ] Update `mobile-app/capacitor.config.json`:
  ```json
  // Remove cleartext: true and change androidScheme to https:
  "server": {
    "androidScheme": "https"
  }
  ```

### 2. Rebuild the Android APK
- After changing the API URL and removing cleartext, rebuild the APK:
  ```
  npx cap sync android
  npx cap open android
  (Build > Generate Signed Bundle/APK in Android Studio)
  ```

---

## Web Server (PHP)

### 3. Environment Configuration (`config/env.php`)
- [ ] Change `APP_ENV` from `'development'` to `'production'`.
- [ ] Set a strong, unique `QR_SECRET_KEY` (at least 32 random characters).
- [ ] Set a strong, unique `KIOSK_API_KEY`.
- [ ] Configure SMTP settings with real credentials.
- [ ] Ensure `config/env.php` is listed in `.gitignore` and never committed to source control.

### 4. PHP Configuration
- [ ] Confirm `display_errors = Off` in `php.ini` for production.
- [ ] Confirm `log_errors = On` and set a proper `error_log` path.

### 5. Database
- [ ] Use a strong MySQL password (not blank).
- [ ] Create a dedicated MySQL user with minimal privileges (not `root`).

### 6. CORS Restriction
- [ ] In all `api/*.php` files, restrict `Access-Control-Allow-Origin: *` to your specific production domain.
  ```php
  header('Access-Control-Allow-Origin: https://yourdomain.com');
  ```

### 7. Backup
- [ ] Schedule regular automated backups using a cron job.
- [ ] Test the restore procedure using the steps in `RECOVERY.md`.
