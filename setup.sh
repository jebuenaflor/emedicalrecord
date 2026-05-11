#!/bin/bash
echo "=== OpenEMR Setup Script ==="

# Ask for passwords
read -sp "Enter MySQL root password: " ROOT_PASS
echo
read -sp "Enter OpenEMR DB password to set: " DB_PASS
echo

# 1. Install dependencies
apt-get update
apt-get install -y apache2 mysql-server php php-mysql php-curl php-gd \
  php-xml php-mbstring php-zip php-soap libapache2-mod-php

# 2. Create missing directories first
mkdir -p /var/www/html/openemr/sites/default/documents
mkdir -p /var/www/html/openemr/sites/default

# 3. Set correct permissions
chown -R www-data:www-data /var/www/html/openemr
chmod -R 755 /var/www/html/openemr
chmod -R 700 /var/www/html/openemr/sites/default/documents

# 4. Create the database
mysql -u root -p"$ROOT_PASS" -e "CREATE DATABASE IF NOT EXISTS openemr;"
mysql -u root -p"$ROOT_PASS" -e "CREATE USER IF NOT EXISTS 'openemr'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -u root -p"$ROOT_PASS" -e "GRANT ALL ON openemr.* TO 'openemr'@'localhost';"
mysql -u root -p"$ROOT_PASS" openemr < openemr_db.sql

# 5. Generate sqlconf.php
cat > /var/www/html/openemr/sites/default/sqlconf.php <<EOF
<?php
\$host   = 'localhost';
\$port   = '3306';
\$login  = 'openemr';
\$pass   = '$DB_PASS';
\$dbase  = 'openemr';
\$disable_utf8_flag = false;
\$transaction_support = true;
EOF

# 6. Enable Apache mod_rewrite
a2enmod rewrite
systemctl restart apache2

echo "=== Setup Complete! ==="
