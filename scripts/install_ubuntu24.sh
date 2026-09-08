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
sudo a2enmod rewrite
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
2. Create the Apache vhost (see scripts/apache-vhost-example.conf),
   pointing DocumentRoot at ${APP_DIR}/public, then:
     sudo a2ensite university-outreach.conf && sudo systemctl reload apache2
     sudo certbot --apache -d outreach.yourdomain.org
3. Import your contact list:
     php ${APP_DIR}/scripts/import_contacts.php /path/to/contacts.csv
4. Edit the baseline email template (sql/schema.sql's sample row, or via SQL)
   to your organization's real script.
5. If this form will be publicly reachable, consider adding a CAPTCHA
   (e.g. hCaptcha) to public/index.php to deter abuse -- basic per-email
   and per-IP throttling is already built in (see config.php).

EOM
