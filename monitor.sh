#!/bin/bash
LOG="./monitor_logs/monitor_$(date +%Y%m%d_%H%M%S).log"
mkdir -p ./monitor_logs

snapshot() {
    echo "========================================" >> $LOG
    echo "[$1] $(date '+%H:%M:%S')" >> $LOG
    echo "--- Memory ---" >> $LOG
    free -h >> $LOG
    echo "--- Processes ---" >> $LOG
    ps aux | grep -E "mysqld|redis|php" | grep -v grep | \
        awk '{printf "%-25s CPU:%-6s RAM:%s\n", $11, $3, $4}' >> $LOG
    echo "--- MySQL ---" >> $LOG
    mysql -u root -e "SHOW STATUS WHERE Variable_name IN (
        'Threads_connected','Threads_running',
        'Innodb_row_lock_waits','Queries');" 2>/dev/null >> $LOG
    echo "" >> $LOG
}

echo "Taking BEFORE snapshot..."
snapshot "BEFORE"

echo "Run k6 now in Terminal 2"
echo "Press Enter when k6 is done..."
read

echo "Taking AFTER snapshot..."
snapshot "AFTER"

echo "Done! Log saved to: $LOG"
echo ""
echo "=== COMPARISON ==="
grep -E "\[BEFORE\]|\[AFTER\]|Threads_connected|Threads_running|Innodb_row_lock" $LOG
