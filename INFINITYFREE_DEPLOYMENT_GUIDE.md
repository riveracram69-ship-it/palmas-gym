# 100% FREE DEPLOYMENT GUIDE — INFINITYFREE
## PALMA'S ELITE GYM MANAGEMENT SYSTEM

Follow these exact steps to host your gym management system online **24/7 for 100% free** without entering any credit card.

---

## STEP 1: Create Your Free InfinityFree Account & Domain

1. Go to [infinityfree.com](https://www.infinityfree.com/) and click **Sign Up Now** (free).
2. Once signed in to the dashboard, click **Create Account**.
3. Choose a domain type: Select **Free Subdomain**.
   * Example domain: `palmasgym`
   * Domain extension: choose `.infinityfreeapp.com` or `.free.nf` or `.great-site.net`
   * Your full website URL will be: `https://palmasgym.infinityfreeapp.com`
4. Click **Check Availability** and then click **Create Account**.
5. Note down your:
   * **Account Username** (e.g. `if0_38123456`)
   * **Account Password**
   * **Control Panel Link**

---

## STEP 2: Create the MySQL Database & Import Data

1. In your InfinityFree client area, click **Manage** -> **Control Panel** (vPanel).
2. Click **MySQL Databases** under the *Databases* section.
3. In **Create New Database**, enter: `gym_management` and click **Create Database**.
4. Note the database connection details displayed on screen:
   * **MySQL Hostname**: (e.g. `sql200.infinityfree.com` or `sql101.infinityfree.com` — **Note: It is NOT localhost!**)
   * **MySQL Database Name**: (e.g. `if0_38123456_gym_management`)
   * **MySQL Username**: (e.g. `if0_38123456`)
   * **MySQL Password**: (Your InfinityFree account/vPanel password)
5. Under the database list, click the **phpMyAdmin** button next to your new database.
6. In phpMyAdmin:
   * Click the **Import** tab at the top.
   * Click **Choose File** and select `gym/gym_management.sql` from your computer.
   * Scroll down and click **Import** (or **Go**).
   * All 11 tables will be created and populated!

---

## STEP 3: Upload Your Files

You can upload files using either the **Web File Manager** or **FileZilla (FTP)**.

### Option A: Web File Manager (Quickest for small batches)
1. In your InfinityFree dashboard, click **File Manager**.
2. Open the **`htdocs`** folder (Delete the default `index2.html` file inside it).
3. Upload all the files and folders from inside `c:\xam\htdocs\gggym\gym\` directly into `htdocs`.

### Option B: FileZilla (Best for uploading entire folder in 1-click)
1. Download free [FileZilla Client](https://filezilla-project.org/).
2. Connect using your FTP details (found in your InfinityFree dashboard):
   * **Host**: `ftpupload.net`
   * **Username**: `if0_38123456`
   * **Password**: (Your vPanel password)
   * **Port**: `21`
3. In FileZilla, open the **`htdocs`** folder on the remote side.
4. Drag and drop all files from `c:\xam\htdocs\gggym\gym\` into `htdocs`.

---

## STEP 4: Configure Your Production `.env` on InfinityFree

1. In the InfinityFree **File Manager**, inside `htdocs`, find or create a file named `.env`.
2. Enter your InfinityFree database details:

```env
APP_ENV=production
APP_URL=https://palmasgym.infinityfreeapp.com

DB_HOST=sql200.infinityfree.com
DB_PORT=3306
DB_NAME=if0_38123456_gym_management
DB_USER=if0_38123456
DB_PASS=YOUR_INFINITYFREE_PASSWORD

QR_SECRET_KEY=palmas_elite_gym_secret_2026_infinityfree_key!
KIOSK_API_KEY=kiosk_api_palmas_2026
CRON_SECRET_KEY=palmas_cron_secret_2026

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=palmaselitegym.system@gmail.com
SMTP_PASS=kanb bjff zzce fhwj
SMTP_FROM=palmaselitegym.system@gmail.com
SMTP_FROM_NAME="Palma's Elite Gym"
```
3. Save the file.

---

## STEP 5: Activate 100% Free SSL Certificate (`https://`)

1. In the InfinityFree Client Area, click **Free SSL Certificates** at the top menu.
2. Click **New SSL Certificate**.
3. Choose your domain (`palmasgym.infinityfreeapp.com`) and select **Let's Encrypt** or **GoGetSSL**.
4. Click **Set up CNAME Records Automatically**.
5. Wait 2 to 5 minutes, then click **Request Certificate**.
6. Once issued, click **Install SSL Certificate Automatically**.
7. Your site is now fully secured with **`https://`**!

---

## STEP 6: Access Your Live System!

* **Admin / Staff Login**: `https://palmasgym.infinityfreeapp.com/login.php`
  * Username: `admin` (or admin email)
* **Member Web Portal**: `https://palmasgym.infinityfreeapp.com/member/login.php`
* **Attendance Kiosk Scanner**: `https://palmasgym.infinityfreeapp.com/attendance.php`
* **API Endpoint (for Mobile App)**: `https://palmasgym.infinityfreeapp.com/api`

---

## STEP 7: Point Your Android Mobile App to InfinityFree

In `gym/mobile-app/www/index.html` on line 532, set:
```javascript
let API_URL = "https://palmasgym.infinityfreeapp.com/api";
```
Build your APK using `gym/mobile-app/build-release-apk.bat`, and your Android app will connect over the internet to your free InfinityFree cloud database from anywhere in the world!
