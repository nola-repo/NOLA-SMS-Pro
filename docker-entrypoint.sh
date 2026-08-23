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

# ── Pass Cloud Run secrets into Apache environment for PHP getenv() ───────────
# Apache does not automatically inherit shell environment variables.
# We write a SetEnv conf so PHP can read JWT_SECRET and WEBHOOK_SECRET.
APACHE_SECRETS_CONF="/etc/apache2/conf-available/nola-secrets.conf"
cat > "$APACHE_SECRETS_CONF" <<EOF
$([ -n "$JWT_SECRET" ] && echo "SetEnv JWT_SECRET $JWT_SECRET")
$([ -n "$WEBHOOK_SECRET" ] && echo "SetEnv WEBHOOK_SECRET $WEBHOOK_SECRET")
$([ -n "$GHL_CLIENT_ID" ] && echo "SetEnv GHL_CLIENT_ID $GHL_CLIENT_ID")
$([ -n "$GHL_CLIENT_SECRET" ] && echo "SetEnv GHL_CLIENT_SECRET $GHL_CLIENT_SECRET")
$([ -n "$SEMAPHORE_API_KEY" ] && echo "SetEnv SEMAPHORE_API_KEY $SEMAPHORE_API_KEY")
$([ -n "$FIREBASE_CREDENTIALS" ] && echo "SetEnv FIREBASE_CREDENTIALS $FIREBASE_CREDENTIALS")
EOF
a2enconf nola-secrets 2>/dev/null || true
echo "[entrypoint] Apache secrets env conf written."

# ── Start Apache ──────────────────────────────────────────────────────────────
exec apache2-foreground
