# Local WAMP Setup — Detailed Guide

**For:** South Side Supplies Dolibarr Local Dev  
**Date:** 2026-06-01  
**Prerequisites:** WAMP installed with PHP 8.3.14, MariaDB running, Git installed

---

## Phase 1: Folder Structure & Git ✓ COMPLETE

Already done. Summary:

```
C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr/
├── .gitignore                  # Excludes secrets, data, runtime files
├── .env.example                # Template for local environment variables
├── README.md                   # Project overview
├── SETUP.md                    # Quick reference (this document)
├── dolibarr-core/              # Dolibarr source (Git submodule)
├── custom/                     # South Side Supplies customizations
├── scripts/                    # Utility scripts (import, API, backup)
├── docs/                       # Documentation
├── imports/                    # CSV templates and test samples
├── logs/                       # Runtime logs (git-ignored)
└── backups/                    # Database backups (git-ignored)
```

**Git initialized** with user.name and user.email set.

---

## Phase 2: Download & Add Dolibarr as Git Submodule

### Why Git Submodule?

- Pins Dolibarr to a specific version (e.g., 17.0.0)
- Easy to upgrade: `git submodule update --remote`
- Core code not cluttering your repo
- Reproducible: anyone can clone and get exact same version

### Steps

```bash
cd C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr

# Add Dolibarr as submodule
git submodule add https://github.com/Dolibarr/dolibarr.git dolibarr-core

# Enter submodule and pin to latest stable version
cd dolibarr-core
git fetch --all --tags
git checkout tags/17.0.0  # Replace with latest stable tag from https://github.com/Dolibarr/dolibarr/releases

# Return to root and commit
cd ..
git add dolibarr-core .gitmodules
git commit -m "Add Dolibarr 17.0 as Git submodule"

# Verify structure
ls dolibarr-core/htdocs/
# Should show: admin, api, core, custom, public, static, etc.
```

### Verify Submodule

```bash
git submodule status
# Should show: [hash] dolibarr-core (tag: 17.0.0)
```

---

## Phase 3: Configure WAMP Virtual Host

### 3a. Edit Apache Virtual Hosts Config

**File:** `C:\wamp64\bin\apache\apache2.4.x\conf\extra\httpd-vhosts.conf`

(Note: Your Apache version may differ; check the folder name in `C:\wamp64\bin\apache\`)

**Add this block** at the end of the file:

```apache
<VirtualHost *:80>
  ServerName dolibarr.local
  DocumentRoot "C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr\dolibarr-core\htdocs"
  
  <Directory "C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr\dolibarr-core\htdocs">
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
  </Directory>
  
  # Enable error logging for debugging
  ErrorLog "C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr\logs\apache-error.log"
  CustomLog "C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr\logs\apache-access.log" combined
</VirtualHost>
```

**Save the file.**

### 3b. Edit Windows Hosts File

**File:** `C:\Windows\System32\drivers\etc\hosts`

(Must open Notepad as Administrator)

**Add this line** at the end:

```
127.0.0.1    dolibarr.local
```

**Save the file.**

### 3c. Restart Apache

1. Open WAMP system tray menu
2. Click **Apache** → **Restart Service**
3. Wait for green icon

### 3d. Verify Virtual Host

Open browser and navigate to: **http://dolibarr.local**

**Expected:** Dolibarr installation screen (Step 1 of wizard)

**If blank page or error:**
- Check `C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr\logs\apache-error.log`
- Verify `httpd-vhosts.conf` syntax: each `<VirtualHost>` block must have closing `</VirtualHost>`
- Restart Apache again

---

## Phase 4: Create MariaDB Database & User

### 4a. Connect to MySQL

```bash
mysql -u root -p
# Enter your WAMP MySQL root password (often empty; just press Enter)
```

### 4b. Create Database and User

Paste this entire block into the MySQL prompt:

```sql
CREATE DATABASE dolibarr_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dolibarr_user'@'localhost' IDENTIFIED BY 'YourSecurePassword123';
GRANT ALL PRIVILEGES ON dolibarr_dev.* TO 'dolibarr_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

(Replace `YourSecurePassword123` with a strong password of your choice.)

### 4c. Verify Database Created

```bash
mysql -u dolibarr_user -p dolibarr_dev -e "SELECT 1;"
# Enter password you set above
# Should return: 1
```

### 4d. Create `.env` File

Create file: `C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr\.env`

(Copy from `.env.example`, fill in your values):

```
DOLIBARR_DB_HOST=localhost
DOLIBARR_DB_PORT=3306
DOLIBARR_DB_NAME=dolibarr_dev
DOLIBARR_DB_USER=dolibarr_user
DOLIBARR_DB_PASS=YourSecurePassword123
DOLIBARR_ADMIN_LOGIN=admin
DOLIBARR_ADMIN_PASS=YourAdminPassword123
DOLIBARR_SMTP_FROM=southsidesupplies.yes@gmail.com
```

**Important:** Do NOT commit `.env` to Git. (`.gitignore` excludes it.)

---

## Phase 5: Run Dolibarr Installation Wizard

### 5a. Access the Wizard

Open browser: **http://dolibarr.local**

### 5b. Follow the Wizard

**Step 1: Welcome**
- Click **Next** to proceed

**Step 2: Database Connection**
- Host: `localhost`
- Port: `3306`
- Database name: `dolibarr_dev`
- User: `dolibarr_user`
- Password: (from `.env`)
- Click **Next**

**Step 3: Admin User**
- Login: `admin`
- Password: (choose strong password)
- Email: `admin@southsidesupplies.com`
- Click **Next**

**Step 4: Module Selection**
- Enable (for MVP testing):
  - ✓ CRM & Contacts
  - ✓ Products
  - ✓ Invoicing
  - ✓ Purchase Orders
  - ✓ Inventory
  - ✓ Payments
  - (Leave others unchecked for now)
- Click **Next**

**Step 5: Complete**
- Installation complete!

### 5c. Log In

- URL: http://dolibarr.local
- Username: `admin`
- Password: (you set in wizard)

---

## Phase 6: Create Backup & Restore Script

Create file: `C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr\scripts\backup-restore.sh`

```bash
#!/bin/bash
# Backup and restore script for Dolibarr dev environment
# Usage: ./backup-restore.sh [backup|restore filename]

set -e

BACKUP_DIR="./backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
DB_NAME="dolibarr_dev"
DB_USER="dolibarr_user"

mkdir -p $BACKUP_DIR

if [ "$1" == "backup" ]; then
  echo "Creating backup..."
  
  # Backup database
  read -sp "MySQL password for $DB_USER: " DB_PASS
  echo ""
  mysqldump -u $DB_USER -p$DB_PASS $DB_NAME > $BACKUP_DIR/dolibarr_$TIMESTAMP.sql
  echo "✓ Database backup: $BACKUP_DIR/dolibarr_$TIMESTAMP.sql"
  
  # Backup custom folder
  tar -czf $BACKUP_DIR/custom_$TIMESTAMP.tar.gz custom/
  echo "✓ Custom folder backup: $BACKUP_DIR/custom_$TIMESTAMP.tar.gz"
  
elif [ "$1" == "restore" ] && [ -n "$2" ]; then
  echo "Restoring from backup: $2"
  
  if [ -f "$BACKUP_DIR/$2.sql" ]; then
    read -sp "MySQL password for $DB_USER: " DB_PASS
    echo ""
    mysql -u $DB_USER -p$DB_PASS $DB_NAME < $BACKUP_DIR/$2.sql
    echo "✓ Database restored"
  fi
  
else
  echo "Usage:"
  echo "  Backup:  ./backup-restore.sh backup"
  echo "  Restore: ./backup-restore.sh restore <filename>"
  echo ""
  echo "Recent backups:"
  ls -lht $BACKUP_DIR/ | head -10
fi
```

**Make executable:**

```bash
chmod +x scripts/backup-restore.sh
```

**Test backup:**

```bash
cd C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr
./scripts/backup-restore.sh backup
# Should create: backups/dolibarr_20260601_120000.sql
```

---

## Initial Git Commits

Commit each phase:

```bash
cd C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr

# Phase 1 (already done):
# git add .gitignore README.md SETUP.md .env.example docs/ scripts/ imports/
# git commit -m "Initial project structure for Dolibarr local dev"

# Phase 2 (after submodule added):
git add dolibarr-core .gitmodules
git commit -m "Add Dolibarr 17.0 as Git submodule"

# Phase 6 (after backup script):
git add scripts/backup-restore.sh
git commit -m "[scripts] Add backup/restore script"

# View commits:
git log --oneline
```

---

## Troubleshooting

### WAMP Virtual Host Not Working

**Symptom:** http://dolibarr.local shows nothing or "Connection refused"

**Fix:**
1. Check Apache is running (green icon in WAMP tray)
2. Verify `httpd-vhosts.conf` syntax (each `<VirtualHost>` has `</VirtualHost>`)
3. Restart Apache: right-click WAMP → Apache → Restart Service
4. Check error log: `C:\wamp64\bin\apache\apache2.4.x\logs\error.log`

### Database Connection Error

**Symptom:** "Cannot connect to database" during Dolibarr wizard

**Fix:**
1. Verify database exists: `mysql -u dolibarr_user -p dolibarr_dev -e "SHOW TABLES;"`
2. Check MySQL is running (check WAMP tray)
3. Verify username/password in wizard matches `.env`
4. Restart MySQL: WAMP → MySQL → Restart Service

### Blank Page or PHP Errors

**Symptom:** White screen at http://dolibarr.local

**Fix:**
1. Check PHP error log: `C:\wamp64\logs\php_errors.log`
2. Enable display_errors in `C:\wamp64\bin\php\php8.3.x\php.ini`:
   ```ini
   display_errors = On
   error_reporting = E_ALL
   ```
3. Restart Apache

---

## Next Steps

1. ✓ Phase 1–6 complete
2. Create workflow documentation in `docs/workflows/`
3. Build Reckon CSV import scripts in `scripts/reckon/`
4. Test key workflows (create customer, product, order, invoice)
5. Plan Reckon migration

See `README.md` for overview.
