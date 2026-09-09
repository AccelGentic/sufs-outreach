#!/usr/bin/env bash
# Provisioning script for Ubuntu 24.04 LTS: Apache + PHP + MariaDB
# for the University Outreach Tool.
#
# Review every step before running. It does NOT fully automate TLS
# certificates, firewall rules, or SMTP relay setup -- those need your
# own domain/provider details.

set -euo pipefail

APP_DIR="/var/www/university-outreach"
DB_NAME="university_outreach"
DB_USER="outreach_app"

echo "== Updating packages =="
sudo apt-get update
sudo apt-get -y upgrade

echo "== Installing Apache, PHP, MariaDB =="
sudo apt-get install -y apache2 mariadb-server \
  php php-mysql php-mbstring php-xml php-curl libapache2-mod-php unzip curl

echo "== Enabling required Apache modules =="
# headers and ssl matter as much as rewrite: the vhost examples use
# Header and SSL* directives, and Apache treats a directive from an
# unloaded module as a fatal config error rather than ignoring it.
sudo a2enmod rewrite headers ssl
sudo systemctl enable apache2 mariadb
sudo systemctl restart apache2 mariadb

echo "== Securing MariaDB (interactive) =="
sudo mysql_secure_installation

echo "== Application directory =="
sudo mkdir -p "$APP_DIR"
echo "Copy this project's files into $APP_DIR now (e.g. via scp/rsync/git),"
echo "keeping the same folder layout (config.php.example, includes/, public/, scripts/, sql/)."
read -rp "Press enter once the files are in place..."

echo "== Optional: PHPMailer, for SMTP relay support =="
echo "Skip this if you're using Mailgun (MAIL_DRIVER = 'mailgun' in config.php)."
read -rp "Install PHPMailer now for SMTP relay support (MAIL_DRIVER = 'smtp')? [y/N]: " want_phpmailer
if [[ "${want_phpmailer:-}" =~ ^[Yy]$ ]]; then
  if ! command -v composer >/dev/null 2>&1; then
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
  fi
  ( cd "$APP_DIR" && composer require phpmailer/phpmailer )
fi

echo "== Creating database and app DB user =="
read -rsp "Enter a strong password for the '${DB_USER}' MariaDB user: " DB_PASS
echo
sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "== Loading schema =="
sudo mysql "${DB_NAME}" < "${APP_DIR}/sql/schema.sql"

echo "== Setting file permissions =="
sudo chown -R www-data:www-data "$APP_DIR"
sudo find "$APP_DIR" -type d -exec chmod 750 {} \;
sudo find "$APP_DIR" -type f -exec chmod 640 {} \;

cat <<EOM

== Manual steps still needed ==
1. Copy config.php.example to config.php (same folder, OUTSIDE public/)
   and fill in:
     DB_PASS = '${DB_PASS}'
   plus MAIL_DRIVER ('mailgun' or 'smtp') and the matching credentials
   below it, and your From address.
2. Create the Apache vhost, pointing DocumentRoot at ${APP_DIR}/public:
     - scripts/apache-vhost-example.conf     plain HTTP, to get started
     - scripts/apache-vhost-ssl-example.conf full HTTPS setup (port 80
       redirect + TLS + security headers), for production
   then:
     sudo a2enmod ssl headers rewrite
     sudo a2ensite university-outreach.conf
     sudo apache2ctl configtest && sudo systemctl reload apache2
     sudo certbot certonly --webroot -w ${APP_DIR}/public -d outreach.yourdomain.org
3. Import your contact list:
     php ${APP_DIR}/scripts/import_contacts.php /path/to/contacts.csv
4. Set an admin password for the browser-based template editor:
     php ${APP_DIR}/scripts/make_admin_hash.php
   and paste the ADMIN_PASSWORD_HASH line it prints into config.php.
   Then edit the baseline email template at https://your-host/admin/
   (see "Editing the email text" in the README). Until that hash is
   set, the admin area refuses every login.
5. If this form will be publicly reachable, consider adding a CAPTCHA
   (e.g. hCaptcha) to public/index.php to deter abuse -- basic per-email
   and per-IP throttling is already built in (see config.php).

EOM
