#!/bin/bash
set -e

# ── Generate Laravel .env at container startup ────────────────────────────────
# Secrets are injected by Cloud Run as environment variables.
# This avoids baking sensitive values into the Docker image.

LARAVEL_DIR="/var/www/html/laravel"
ENV_FILE="$LARAVEL_DIR/.env"

cat > "$ENV_FILE" <<EOF
APP_NAME=NolaSMSPro
APP_ENV=production
APP_KEY=${LARAVEL_APP_KEY}
APP_DEBUG=false
APP_URL=${APP_URL:-https://sms-api-116662437564.asia-southeast1.run.app}

LOG_CHANNEL=stderr
LOG_LEVEL=error

SESSION_DRIVER=file
CACHE_STORE=${REDIS_HOST:+redis}
CACHE_STORE=${CACHE_STORE:-file}
QUEUE_CONNECTION=sync

REDIS_HOST=${REDIS_HOST:-127.0.0.1}
REDIS_PORT=${REDIS_PORT:-6379}
REDIS_PASSWORD=${REDIS_PASSWORD:-null}
EOF

echo "[entrypoint] Laravel .env generated."

# ── Start Apache ──────────────────────────────────────────────────────────────
exec apache2-foreground
