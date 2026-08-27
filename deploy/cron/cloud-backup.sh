#!/usr/bin/env bash
# ==============================================================================
# CLOUD BACKUP SCRIPT - PALMA'S ELITE GYM MANAGEMENT SYSTEM
# ==============================================================================
# Performs timestamped mysqldump and uploads to S3-compatible cloud storage (Cloudflare R2 / AWS S3)
# ==============================================================================

set -euo pipefail

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_DIR="/tmp/backups"
BACKUP_FILE="gym_backup_${TIMESTAMP}.sql.gz"
LOCAL_PATH="${BACKUP_DIR}/${BACKUP_FILE}"

mkdir -p "$BACKUP_DIR"

echo "📦 [1/3] Generating compressed MySQL dump..."
mysqldump \
    --host="${DB_HOST}" \
    --port="${DB_PORT:-3306}" \
    --user="${DB_USER}" \
    --password="${DB_PASS}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    "${DB_NAME}" | gzip -9 > "$LOCAL_PATH"

echo "☁️ [2/3] Uploading backup to S3-compatible storage..."
if command -v aws >/dev/null 2>&1 && [ -n "${S3_BUCKET_NAME:-}" ]; then
    aws s3 cp "$LOCAL_PATH" "s3://${S3_BUCKET_NAME}/database-backups/${BACKUP_FILE}" \
        ${S3_ENDPOINT_URL:+--endpoint-url "$S3_ENDPOINT_URL"}
    echo "✅ Backup successfully uploaded to s3://${S3_BUCKET_NAME}/database-backups/${BACKUP_FILE}"
else
    echo "⚠️ AWS CLI not found or S3_BUCKET_NAME not set. Backup preserved locally at: $LOCAL_PATH"
fi

echo "🧹 [3/3] Pruning temporary backup files..."
rm -f "$LOCAL_PATH"

echo "🎉 Database backup process finished successfully at $(date)."
