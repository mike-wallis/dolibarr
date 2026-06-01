# Quick Setup Reference

Complete setup instructions are in `docs/setup/01-local-wamp-setup.md`. This is a quick checklist.

## Phase 1: Folder & Git ✓ DONE

```bash
# Already completed:
# - Created folder structure
# - Initialized Git repo
# - Created .gitignore
```

---

## Phase 2: Download Dolibarr & Git Submodule

```bash
cd C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr

# Add Dolibarr as Git submodule (pinned to latest stable)
git submodule add https://github.com/Dolibarr/dolibarr.git dolibarr-core
cd dolibarr-core
git checkout tags/17.0.0  # Adjust to latest stable tag
cd ..
git commit -m "Add Dolibarr 17.0 as submodule"

# Verify structure
ls dolibarr-core/htdocs/
```

---

## Phase 3: Configure WAMP Vhost

### 3a. Edit `C:\wamp64\bin\apache\apache2.4.x\conf\extra\httpd-vhosts.conf`

Add (adjust version if needed; check your Apache version):

```apache
<VirtualHost *:80>
  ServerName dolibarr.local
  DocumentRoot "C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr\dolibarr-core\htdocs"
  
  <Directory "C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr\dolibarr-core\htdocs">
    AllowOverride All
    Require all granted
  </Directory>
</VirtualHost>
```

Save and restart Apache (via WAMP menu).

### 3b. Edit `C:\Windows\System32\drivers\etc\hosts`

Add (run Notepad as Administrator):

```
127.0.0.1    dolibarr.local
```

Save.

### 3c. Verify

Open browser: http://dolibarr.local  
Should see Dolibarr install screen (or existing Dolibarr if DB exists).

---

## Phase 4: Create MariaDB Database

```bash
mysql -u root -p
```

Paste into MySQL prompt:

```sql
CREATE DATABASE dolibarr_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dolibarr_user'@'localhost' IDENTIFIED BY 'YourSecurePassword';
GRANT ALL PRIVILEGES ON dolibarr_dev.* TO 'dolibarr_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Create `.env` file (DO NOT COMMIT)

Create `C:\Users\mhwal\OneDrive\SouthSideSupplies\Dolibarr\.env`:

```
DOLIBARR_DB_HOST=localhost
DOLIBARR_DB_PORT=3306
DOLIBARR_DB_NAME=dolibarr_dev
DOLIBARR_DB_USER=dolibarr_user
DOLIBARR_DB_PASS=YourSecurePassword
DOLIBARR_ADMIN_LOGIN=admin
DOLIBARR_ADMIN_PASS=YourAdminPassword
DOLIBARR_SMTP_FROM=southsidesupplies.yes@gmail.com
```

---

## Phase 5: Dolibarr Install Wizard

1. Open http://dolibarr.local
2. Follow wizard:
   - Database: `dolibarr_dev`, `dolibarr_user`, your password
   - Admin user: `admin` / your password
   - Enable modules: CRM, Invoicing, Inventory (minimal for now)
3. Log in and verify dashboard

---

## Phase 6: Backup & Restore Script

Create `scripts/backup-restore.sh`:

```bash
#!/bin/bash
BACKUP_DIR="./backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
DB_NAME="dolibarr_dev"
DB_USER="dolibarr_user"

# Backup database
mysqldump -u $DB_USER -p $DB_NAME > $BACKUP_DIR/dolibarr_$TIMESTAMP.sql
echo "✓ Database backup: $BACKUP_DIR/dolibarr_$TIMESTAMP.sql"

# Backup custom folder
tar -czf $BACKUP_DIR/custom_$TIMESTAMP.tar.gz custom/
echo "✓ Custom folder backup: $BACKUP_DIR/custom_$TIMESTAMP.tar.gz"

echo "Done."
```

Make executable: `chmod +x scripts/backup-restore.sh`

---

## Git Commits

After each phase, commit:

```bash
# After Phase 2
git add dolibarr-core .gitmodules
git commit -m "Add Dolibarr 17.0 as Git submodule"

# After Phase 6
git add scripts/backup-restore.sh
git commit -m "[scripts] Add backup/restore script"
```

---

## Next: Documentation

After Phase 5, create workflow guides in `docs/workflows/`:
- 01-create-customer.md
- 02-create-supplier.md
- 03-create-product.md
- etc. (see plan)

---

## Troubleshooting

- **WAMP vhost not loading?** Restart Apache via WAMP menu; verify `httpd-vhosts.conf` syntax
- **Database connection error?** Check `.env` credentials match MySQL user
- **Dolibarr blank page?** Check `logs/` folder for PHP errors; enable error logging in WAMP PHP

---

See `docs/setup/01-local-wamp-setup.md` for complete details.
