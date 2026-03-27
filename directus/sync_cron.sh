#!/bin/bash
###############################################################################
# Koha → Directus Sync - Cron Wrapper Script
#
# Runs all syncs in sequence:
# 1. Branches (libraries) - fast, reference data
# 2. Biblios (books) - medium, ~70k records
# 3. Items (exemplar) - large, ~155k records
# 4. Holds (reservations) - fast, ~1900 holds aggregated to ~1140 biblios
# 5. Enrich metadata - Gemini AI enrichment (up to 1000 books/run)
# 6. Qdrant vectors - hybrid search embeddings (dense + sparse)
#
# Sends start/success/fail pings to healthchecks.io with per-step payload.
#
# Crontab entry (daily at 03:00):
# 0 3 * * * /home/httpd/fbg-intranet/integrationer/integration-koha-web/directus/sync_cron.sh
#
# @package    Falkenbergs kommun
# @subpackage Koha Sync
###############################################################################

# Ensure uv is in PATH (not available in cron's minimal PATH)
export PATH="/home/httpd/fbg-intranet/.local/bin:$PATH"

# Configuration
SCRIPT_DIR="/home/httpd/fbg-intranet/integrationer/integration-koha-web/directus"
LOG_FILE="${SCRIPT_DIR}/sync.log"
ERROR_LOG="${SCRIPT_DIR}/sync_errors.log"
MAX_LOG_SIZE=10485760  # 10MB

# Healthchecks.io configuration
HC_BASE_URL="https://healthchecks.utvecklingfalkenberg.se"
ENV_FILE="$(dirname "$SCRIPT_DIR")/.env"
HC_ID=""
if [ -f "$ENV_FILE" ]; then
    HC_ID=$(grep -E '^HEALTHCHECK_SYNC_ID=' "$ENV_FILE" | cut -d'=' -f2- | tr -d '"' | tr -d "'")
fi

# Send healthchecks.io ping (fire-and-forget)
# Usage: hc_ping [start|fail|""] [payload_text]
hc_ping() {
    [ -z "$HC_ID" ] && return 0
    local endpoint="${1:+/$1}"
    local payload="${2:-}"
    if [ -n "$payload" ]; then
        curl -fsS --retry 3 --max-time 5 -o /dev/null \
            -X POST -d "$payload" \
            "${HC_BASE_URL}/ping/${HC_ID}${endpoint}" 2>/dev/null &
    else
        curl -fsS --retry 3 --max-time 5 -o /dev/null \
            "${HC_BASE_URL}/ping/${HC_ID}${endpoint}" 2>/dev/null &
    fi
}

# Sync scripts in execution order
SYNC_BRANCHES="${SCRIPT_DIR}/sync_koha_branches.php"
SYNC_BIBLIOS="${SCRIPT_DIR}/sync_koha_to_directus.php"
SYNC_ITEMS="${SCRIPT_DIR}/sync_koha_items.php"
SYNC_HOLDS="${SCRIPT_DIR}/sync_koha_holds.php"

# Get current timestamp
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
START_EPOCH=$(date +%s)

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

# Function to run a sync step and capture output for healthcheck payload
run_sync() {
    local name=$1
    local script=$2
    local step_timestamp=$(date '+%Y-%m-%d %H:%M:%S')

    echo "[$step_timestamp] Starting sync: ${name}..." >> "$LOG_FILE"

    if [ ! -f "$script" ]; then
        echo "[$step_timestamp] WARNING: Script not found: ${script}, skipping" >> "$LOG_FILE"
        STEP_OUTPUTS="${STEP_OUTPUTS}--- ${name}: SKIPPED (script not found) ---\n\n"
        return 0
    fi

    local step_output
    step_output=$(php "$script" 2>&1)
    local exit_code=$?

    # Write full output to log file (same as before)
    echo "$step_output" >> "$LOG_FILE"

    # Extract key stats for healthcheck payload
    local stats_summary
    stats_summary=$(echo "$step_output" | grep -E '^\s*(Created|Updated|Skipped|Errors|Duration|Marked inactive|Duplicates deleted|Koha (biblios|libraries|items|holds)|Directus before|Total in Directus|Hold counts created|Hold counts updated|Biblios updated|Unique biblios|Peak memory):' | sed 's/^[[:space:]]*//' | head -12)

    if [ $exit_code -eq 0 ]; then
        echo "[$step_timestamp] ${name}: SUCCESS" >> "$LOG_FILE"
        STEP_OUTPUTS="${STEP_OUTPUTS}--- ${name}: OK ---\n${stats_summary}\n\n"
        return 0
    else
        echo "[$step_timestamp] ${name}: FAILED" >> "$LOG_FILE"
        echo "[$step_timestamp] ERROR: ${name} sync failed! Check ${LOG_FILE} for details." >> "$ERROR_LOG"
        STEP_OUTPUTS="${STEP_OUTPUTS}--- ${name}: FAILED ---\n${stats_summary}\n\n"
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

# Ping healthchecks: start
hc_ping "start"

# Change to script directory
cd "$SCRIPT_DIR" || {
    echo "[$TIMESTAMP] ERROR: Failed to change to directory: $SCRIPT_DIR" >> "$ERROR_LOG"
    hc_ping "fail" "Failed to change to directory: $SCRIPT_DIR"
    exit 1
}

# Track overall result and per-step output
OVERALL_RESULT="SUCCESS"
STEP_OUTPUTS=""

# Step 1: Branches (fast, reference data - must run first)
run_sync "Branches" "$SYNC_BRANCHES" || OVERALL_RESULT="PARTIAL_FAILURE"

# Step 2: Biblios
run_sync "Biblios" "$SYNC_BIBLIOS" || OVERALL_RESULT="PARTIAL_FAILURE"

# Step 3: Items (largest sync, depends on branches)
run_sync "Items" "$SYNC_ITEMS" || OVERALL_RESULT="PARTIAL_FAILURE"

# Step 4: Holds (aggregated reservations, depends on items + biblios)
run_sync "Holds" "$SYNC_HOLDS" || OVERALL_RESULT="PARTIAL_FAILURE"

# Step 5: Enrich metadata with Gemini AI (up to 1000 books per run)
ENRICH_DIR="/home/httpd/fbg-intranet/integrationer/integration-koha-web/enrich"
if [ -d "$ENRICH_DIR" ]; then
    STEP_TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$STEP_TIMESTAMP] Starting sync: Enrich metadata..." >> "$LOG_FILE"

    enrich_output=$(cd "$ENRICH_DIR" && uv run enrich_from_directus.py --limit 1000 2>&1)
    enrich_exit=$?

    echo "$enrich_output" >> "$LOG_FILE"

    enrich_stats=$(echo "$enrich_output" | grep -E '(Enriched|Skipped|Errors|Total|Cost|books)' | sed 's/^[[:space:]]*//' | head -5)

    if [ $enrich_exit -eq 0 ]; then
        echo "[$STEP_TIMESTAMP] Enrich metadata: SUCCESS" >> "$LOG_FILE"
        STEP_OUTPUTS="${STEP_OUTPUTS}--- Enrich: OK ---\n${enrich_stats}\n\n"
    else
        echo "[$STEP_TIMESTAMP] Enrich metadata: FAILED" >> "$LOG_FILE"
        echo "[$STEP_TIMESTAMP] ERROR: Enrich failed! Check ${LOG_FILE} for details." >> "$ERROR_LOG"
        OVERALL_RESULT="PARTIAL_FAILURE"
        STEP_OUTPUTS="${STEP_OUTPUTS}--- Enrich: FAILED ---\n${enrich_stats}\n\n"
    fi
    cd "$SCRIPT_DIR"
fi

# Step 6: Qdrant vector sync (depends on all previous data being current)
QDRANT_DIR="/home/httpd/fbg-intranet/integrationer/integration-koha-web/qdrant"
if [ -d "$QDRANT_DIR" ]; then
    STEP_TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$STEP_TIMESTAMP] Starting sync: Qdrant vectors..." >> "$LOG_FILE"

    qdrant_output=$(cd "$QDRANT_DIR" && uv run sync_to_qdrant.py 2>&1)
    qdrant_exit=$?

    echo "$qdrant_output" >> "$LOG_FILE"

    qdrant_stats=$(echo "$qdrant_output" | grep -E '^\s*(Upsertade|Raderade|Oförändrade|Tid):' | sed 's/^[[:space:]]*//' | head -5)

    if [ $qdrant_exit -eq 0 ]; then
        echo "[$STEP_TIMESTAMP] Qdrant vectors: SUCCESS" >> "$LOG_FILE"
        STEP_OUTPUTS="${STEP_OUTPUTS}--- Qdrant: OK ---\n${qdrant_stats}\n\n"
    else
        echo "[$STEP_TIMESTAMP] Qdrant vectors: FAILED" >> "$LOG_FILE"
        echo "[$STEP_TIMESTAMP] ERROR: Qdrant sync failed! Check ${LOG_FILE} for details." >> "$ERROR_LOG"
        OVERALL_RESULT="PARTIAL_FAILURE"
        STEP_OUTPUTS="${STEP_OUTPUTS}--- Qdrant: FAILED ---\n${qdrant_stats}\n\n"
    fi
    cd "$SCRIPT_DIR"
fi

# Log completion
FINISH_TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
DURATION=$(( $(date +%s) - START_EPOCH ))

echo "[$FINISH_TIMESTAMP] Full sync result: $OVERALL_RESULT" >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"
echo "" >> "$LOG_FILE"

# Build healthcheck payload
PAYLOAD=$(printf "Koha Sync Pipeline - %s\nStarted: %s\nFinished: %s\nDuration: ~%dm\n\n%b" \
    "$OVERALL_RESULT" "$TIMESTAMP" "$FINISH_TIMESTAMP" "$((DURATION / 60))" "$STEP_OUTPUTS")

# Ping healthchecks: success or fail, with payload
if [ "$OVERALL_RESULT" = "SUCCESS" ]; then
    hc_ping "" "$PAYLOAD"
else
    hc_ping "fail" "$PAYLOAD"
fi

# Wait for background curl processes to finish
wait

# Exit with appropriate code
if [ "$OVERALL_RESULT" = "SUCCESS" ]; then
    exit 0
else
    exit 1
fi
