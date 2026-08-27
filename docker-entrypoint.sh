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

JWT_SECRET=${JWT_SECRET}

REDIS_HOST=${REDIS_HOST:-127.0.0.1}
REDIS_PORT=${REDIS_PORT:-6379}
REDIS_PASSWORD=${REDIS_PASSWORD:-null}
EOF

echo "[entrypoint] Laravel .env generated."

# ── Pass Cloud Run secrets into Apache environment for PHP getenv() ───────────
# Apache does not automatically inherit shell environment variables.
# PassEnv forwards Cloud Run secrets into PHP getenv() without quoting issues.
APACHE_SECRETS_CONF="/etc/apache2/conf-available/nola-secrets.conf"
cat > "$APACHE_SECRETS_CONF" <<'EOF'
PassEnv JWT_SECRET
PassEnv WEBHOOK_SECRET
PassEnv GHL_CLIENT_ID
PassEnv GHL_CLIENT_SECRET
PassEnv SEMAPHORE_API_KEY
PassEnv FIREBASE_CREDENTIALS
PassEnv APP_BASE_URL
PassEnv APP_ENV
PassEnv ENVIRONMENT
PassEnv FRONTEND_APP_URL
PassEnv AGENCY_APP_URL
PassEnv GHL_REDIRECT_URI
PassEnv GHL_AGENCY_REDIRECT_URI
PassEnv GHL_CRM_BASE_URL
PassEnv GHL_CUSTOM_PAGE_ID
PassEnv PORT
PassEnv K_SERVICE
EOF
a2enconf nola-secrets 2>/dev/null || true
echo "[entrypoint] Apache secrets env conf written."

if [ -n "$JWT_SECRET" ]; then
  printf '%s' "$JWT_SECRET" > /var/www/html/laravel/.env.jwt_secret
  chmod 640 /var/www/html/laravel/.env.jwt_secret
  chown www-data:www-data /var/www/html/laravel/.env.jwt_secret 2>/dev/null || true
fi

# ── Start Apache ──────────────────────────────────────────────────────────────
exec apache2-foreground
