#!/bin/bash
###############################################################################
# Koha → Directus Sync - Cron Wrapper Script
#
# Runs all three syncs in sequence:
# 1. Branches (libraries) - fast, reference data
# 2. Biblios (books) - medium, ~70k records
# 3. Items (exemplar) - large, ~155k records
#
# Crontab entry (daily at 03:00):
# 0 3 * * * /home/httpd/fbg-intranet/integrationer/integration-koha-web/directus/sync_cron.sh
#
# @package    Falkenbergs kommun
# @subpackage Koha Sync
###############################################################################

# Configuration
SCRIPT_DIR="/home/httpd/fbg-intranet/integrationer/integration-koha-web/directus"
LOG_FILE="${SCRIPT_DIR}/sync.log"
ERROR_LOG="${SCRIPT_DIR}/sync_errors.log"
MAX_LOG_SIZE=10485760  # 10MB

# Sync scripts in execution order
SYNC_BRANCHES="${SCRIPT_DIR}/sync_koha_branches.php"
SYNC_BIBLIOS="${SCRIPT_DIR}/sync_koha_to_directus.php"
SYNC_ITEMS="${SCRIPT_DIR}/sync_koha_items.php"

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

# Function to run a sync step
run_sync() {
    local name=$1
    local script=$2
    local step_timestamp=$(date '+%Y-%m-%d %H:%M:%S')

    echo "[$step_timestamp] Starting sync: ${name}..." >> "$LOG_FILE"

    if [ ! -f "$script" ]; then
        echo "[$step_timestamp] WARNING: Script not found: ${script}, skipping" >> "$LOG_FILE"
        return 0
    fi

    if php "$script" >> "$LOG_FILE" 2>&1; then
        echo "[$step_timestamp] ${name}: SUCCESS" >> "$LOG_FILE"
        return 0
    else
        echo "[$step_timestamp] ${name}: FAILED" >> "$LOG_FILE"
        echo "[$step_timestamp] ERROR: ${name} sync failed! Check ${LOG_FILE} for details." >> "$ERROR_LOG"
        return 1
    fi
}

# Rotate logs if needed
rotate_log_if_needed "$LOG_FILE"
rotate_log_if_needed "$ERROR_LOG"

# Log start
echo "" >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"
echo "[$TIMESTAMP] Starting Koha -> Directus full sync..." >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"

# Change to script directory
cd "$SCRIPT_DIR" || {
    echo "[$TIMESTAMP] ERROR: Failed to change to directory: $SCRIPT_DIR" >> "$ERROR_LOG"
    exit 1
}

# Track overall result
OVERALL_RESULT="SUCCESS"

# Step 1: Branches (fast, reference data - must run first)
run_sync "Branches" "$SYNC_BRANCHES" || OVERALL_RESULT="PARTIAL_FAILURE"

# Step 2: Biblios
run_sync "Biblios" "$SYNC_BIBLIOS" || OVERALL_RESULT="PARTIAL_FAILURE"

# Step 3: Items (largest sync, depends on branches)
run_sync "Items" "$SYNC_ITEMS" || OVERALL_RESULT="PARTIAL_FAILURE"

# Log completion
FINISH_TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
echo "[$FINISH_TIMESTAMP] Full sync result: $OVERALL_RESULT" >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"
echo "" >> "$LOG_FILE"

# Exit with appropriate code
if [ "$OVERALL_RESULT" = "SUCCESS" ]; then
    exit 0
else
    exit 1
fi
