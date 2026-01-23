#!/bin/bash
###############################################################################
# Koha → Directus Sync - Cron Wrapper Script
#
# This script is designed to be run by cron for automated daily synchronization
# of Koha biblios to Directus. It handles logging and error reporting.
#
# Crontab entry (daily at 03:00):
# 0 3 * * * /home/httpd/fbg-intranet/integrationer/integration-koha-web/directus/sync_cron.sh
#
# @package    Falkenbergs kommun
# @subpackage Koha Biblios Sync
###############################################################################

# Configuration
SCRIPT_DIR="/home/httpd/fbg-intranet/integrationer/integration-koha-web/directus"
SYNC_SCRIPT="${SCRIPT_DIR}/sync_koha_to_directus.php"
LOG_FILE="${SCRIPT_DIR}/sync.log"
ERROR_LOG="${SCRIPT_DIR}/sync_errors.log"
MAX_LOG_SIZE=10485760  # 10MB

# Get current timestamp
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

# Function to rotate log if it's too large
rotate_log_if_needed() {
    local log_file=$1

    if [ -f "$log_file" ]; then
        local size=$(stat -f%z "$log_file" 2>/dev/null || stat -c%s "$log_file" 2>/dev/null)

        if [ "$size" -gt "$MAX_LOG_SIZE" ]; then
            echo "[$TIMESTAMP] Log file exceeds ${MAX_LOG_SIZE} bytes, rotating..." >> "$log_file"
            mv "$log_file" "${log_file}.old"
            touch "$log_file"
        fi
    fi
}

# Rotate logs if needed
rotate_log_if_needed "$LOG_FILE"
rotate_log_if_needed "$ERROR_LOG"

# Log start
echo "" >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"
echo "[$TIMESTAMP] Starting Koha → Directus sync..." >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"

# Change to script directory
cd "$SCRIPT_DIR" || {
    echo "[$TIMESTAMP] ERROR: Failed to change to directory: $SCRIPT_DIR" >> "$ERROR_LOG"
    exit 1
}

# Run sync script and capture output
if php "$SYNC_SCRIPT" >> "$LOG_FILE" 2>&1; then
    SYNC_RESULT="SUCCESS"
    echo "[$TIMESTAMP] Sync completed successfully." >> "$LOG_FILE"
else
    SYNC_RESULT="FAILED"
    echo "[$TIMESTAMP] ERROR: Sync failed! Check logs for details." >> "$ERROR_LOG"
    echo "[$TIMESTAMP] ERROR: Sync failed! See $ERROR_LOG" >> "$LOG_FILE"
fi

# Log completion
echo "[$TIMESTAMP] Sync result: $SYNC_RESULT" >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"
echo "" >> "$LOG_FILE"

# Exit with appropriate code
if [ "$SYNC_RESULT" = "SUCCESS" ]; then
    exit 0
else
    exit 1
fi
