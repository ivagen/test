#!/bin/bash

composer install
php yii migrate --interactive=0
cd /var/www/web/require
npm install
bower install --force --allow-root
gulp
/opt/phpdaemon/bin/phpd start