#!/bin/sh
# Runs once, when the mysql_data volume is initialised: creates the database for PHPUnit
# (phpunit.xml: DB_DATABASE=laravel_test) and lets the application user use it.
set -e

mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_test\`;
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}_test\`.* TO '${MYSQL_USER}'@'%';
SQL
