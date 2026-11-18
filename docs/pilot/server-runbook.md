# Production server runbook

For a VPS with Ubuntu 24.04 (DigitalOcean, Contabo, OVH, or your own).
Shared cPanel works for trying the software (see the README), but real
patient data belongs on a server you control, with backups you have tested.

Replace `dms.example.com` with the clinic's address and `dms` with your
choice of names. Run as a sudo user, not root.

## 1. Server

- 2 vCPU, 4 GB RAM, 40 GB disk is enough for several clinics.
- Firewall: allow 22 (SSH), 80, 443 only.

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

- SSH with keys only; switch off password sign-in in `/etc/ssh/sshd_config`
  (`PasswordAuthentication no`), then `sudo systemctl reload ssh`.
- Automatic security updates:

```bash
sudo apt install unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades
```

## 2. Software

```bash
sudo apt update && sudo apt install -y nginx mysql-server git unzip php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-intl php8.3-gd php8.3-zip php8.3-xml php8.3-curl php8.3-bcmath
```

```bash
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

MySQL: run `sudo mysql_secure_installation`, then create the control
database and the application user as in the README's Setup, step 2.

## 3. Application

```bash
sudo mkdir -p /var/www/dms && sudo chown $USER:www-data /var/www/dms
```

```bash
git clone <your repository> /var/www/dms && cd /var/www/dms
```

```bash
composer install --no-dev --optimize-autoloader
```

```bash
cp .env.example .env && php artisan key:generate
```

In `.env` set at least:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dms.example.com
DB_PASSWORD=<the MySQL password>
BACKUP_PATH=/var/backups/dms
BACKUP_PASSPHRASE=<a long passphrase, kept somewhere safe off the server>
MAIL_MAILER=smtp
# MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS from your mail provider
DMS_PORTAL_UPDATES=false
```

Without `BACKUP_PASSPHRASE` backups are saved unencrypted. Keep the
passphrase somewhere other than the server: without it a backup cannot be
restored.

```bash
php artisan migrate --force
```

```bash
php artisan clinic:create "Clinic Name" dms.example.com --owner-name="Owner Name" --owner-email=owner@example.com
```

```bash
sudo chown -R $USER:www-data storage bootstrap/cache && sudo chmod -R ug+rw storage bootstrap/cache
```

```bash
php artisan optimize && php artisan filament:optimize
```

## 4. Nginx and HTTPS

`/etc/nginx/sites-available/dms`:

```nginx
server {
    listen 80;
    server_name dms.example.com;
    root /var/www/dms/public;
    index index.php;
    client_max_body_size 10m;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/dms /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx
```

```bash
sudo apt install -y certbot python3-certbot-nginx && sudo certbot --nginx -d dms.example.com
```

With Cloudflare in front, set SSL to "Full (strict)".

## 5. Scheduler and queue

Cron for the web user (`sudo crontab -u www-data -e`):

```cron
* * * * * cd /var/www/dms && php artisan schedule:run >> /dev/null 2>&1
```

Queue worker as a service, `/etc/systemd/system/dms-queue.service`:

```ini
[Unit]
Description=DMS queue worker
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/var/www/dms
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now dms-queue
```

## 6. Backups

- `clinic:backup --verify` runs nightly (02:30) once `BACKUP_PATH` is set:
  every database is backed up and test-restored.
- Copy `/var/backups/dms` **off the server** every night (rclone to cloud
  storage, or rsync to another server). A backup on the same disk is lost
  with the disk.
- Uploaded files (logos, signatures, banners) are in
  `storage/app/private/clinics`: copy them too.
- Once a month, restore a backup on another machine to be sure it works.

## 7. Check

```bash
php artisan platform:doctor
```

Fix everything it reports before real patient data goes in.

## 8. Updating

```bash
cd /var/www/dms && git pull && composer install --no-dev --optimize-autoloader
```

```bash
php artisan migrate --force && php artisan clinic:migrate && php artisan optimize && sudo systemctl restart dms-queue php8.3-fpm
```

Take a backup first (`php artisan clinic:backup`). Update outside clinic
hours.
