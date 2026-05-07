#!/bin/bash
# setup.sh — Run this after git pull on new server

echo "=== OpenEMR Setup Script ==="

# 1. Install dependencies (Ubuntu/Debian)
apt-get update
apt-get install -y apache2 mysql-server php php-mysql php-curl php-gd \
  php-xml php-mbstring php-zip php-soap libapache2-mod-php

# 2. Set correct permissions
chown -R www-data:www-data /var/www/html/openemr
chmod -R 755 /var/www/html/openemr
chmod -R 700 /var/www/html/openemr/sites/default/documents

# 3. Create the database
mysql -u root -p"$ROOT_PASS" -e "CREATE DATABASE IF NOT EXISTS openemr;"
mysql -u root -p"$ROOT_PASS" -e "CREATE USER IF NOT EXISTS 'openemr'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -u root -p"$ROOT_PASS" -e "GRANT ALL ON openemr.* TO 'openemr'@'localhost';"
mysql -u root -p"$ROOT_PASS" openemr < openemr_db.sql

# 4. Generate sqlconf.php
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

# 5. Enable Apache mod_rewrite
a2enmod rewrite
systemctl restart apache2

echo "=== Setup Complete! ==="
