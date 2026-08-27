#!/usr/bin/env bash
# ==============================================================================
# AUTOMATED DATABASE BACKUP SCRIPT - PALMA'S ELITE GYM MANAGEMENT SYSTEM
# ==============================================================================
# Recommended: Run daily via cron at 02:00 AM
# ==============================================================================

set -euo pipefail

# Configuration
BACKUP_DIR="${BACKUP_DIR:-/var/backups/palmas-gym}"
RETENTION_DAYS=14
LOG_FILE="/var/log/palmas-gym-backup.log"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/gym_backup_${TIMESTAMP}.sql.gz"

# Ensure directories exist
mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"

log_msg() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "${LOG_FILE}"
}

log_msg "🚀 Starting automated database backup..."

# Source credentials from .env if available
ENV_FILE="/var/www/palmas-gym/.env"
if [ -f "${ENV_FILE}" ]; then
    DB_HOST=$(grep -E '^DB_HOST=' "${ENV_FILE}" | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "127.0.0.1")
    DB_PORT=$(grep -E '^DB_PORT=' "${ENV_FILE}" | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "3306")
    DB_NAME=$(grep -E '^DB_NAME=' "${ENV_FILE}" | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "gym_management")
    DB_USER=$(grep -E '^DB_USER=' "${ENV_FILE}" | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "palmas_gym_user")
    DB_PASS=$(grep -E '^DB_PASS=' "${ENV_FILE}" | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "")
else
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT="${DB_PORT:-3306}"
    DB_NAME="${DB_NAME:-gym_management}"
    DB_USER="${DB_USER:-palmas_gym_user}"
    DB_PASS="${DB_PASS:-}"
fi

# Detect mysqldump binary
MYSQLDUMP_BIN=$(which mysqldump || which mariadb-dump || echo "/usr/bin/mysqldump")

if [ ! -x "${MYSQLDUMP_BIN}" ]; then
    log_msg "❌ Error: mysqldump binary not found."
    exit 1
fi

# Execute dump with single transaction and gzip compression
if [ -n "${DB_PASS}" ]; then
    export MYSQL_PWD="${DB_PASS}"
fi

"${MYSQLDUMP_BIN}" \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USER}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --default-character-set=utf8mb4 \
    "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"

if [ -n "${DB_PASS}" ]; then
    unset MYSQL_PWD
fi

chmod 600 "${BACKUP_FILE}"
BACKUP_SIZE=$(du -h "${BACKUP_FILE}" | cut -f1)

log_msg "✅ Backup successfully created: ${BACKUP_FILE} (${BACKUP_SIZE})"

# Enforce Retention Policy (Purge backups older than RETENTION_DAYS)
log_msg "🧹 Enforcing ${RETENTION_DAYS}-day backup retention policy..."
DELETED_COUNT=$(find "${BACKUP_DIR}" -type f -name "gym_backup_*.sql.gz" -mtime "+${RETENTION_DAYS}" -print -delete | wc -l)
log_msg "✅ Purged ${DELETED_COUNT} obsolete backup files."

log_msg "🎉 Backup operation completed successfully."
