#!/bin/bash
(crontab -l | grep -v "/usr/bin/php /home/srv918729.hstgr.cloud/public_html/public/artisan email:free-trial-end-mail") | crontab -
(crontab -l; echo "0 0 * * * /usr/bin/php /home/srv918729.hstgr.cloud/public_html/public/artisan email:free-trial-end-mail") | crontab -
