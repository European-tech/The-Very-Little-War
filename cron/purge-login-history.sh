#!/bin/bash
# Monthly purge of old login history (>30 days)
mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw -e "DELETE FROM login_history WHERE timestamp < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY));"
