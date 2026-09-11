#!/bin/bash
set -euo pipefail

# Cloud Scheduler: backend maintenance workers.
# Creates or updates:
#   - SMS retry worker every 5 minutes
#   - Provider balance refresh every 15 minutes
#
# Prerequisites:
#   1. gcloud authenticated: gcloud auth login
#   2. Project set: gcloud config set project nola-sms-pro
#   3. Cloud Scheduler API enabled:
#      gcloud services enable cloudscheduler.googleapis.com
#   4. Export CRON_SECRET in this shell before running:
#      export CRON_SECRET='...'
#
# Run:
#   bash scheduler_setup.sh

PROJECT_ID=$(gcloud config get-value project)
REGION="asia-southeast1"
SERVICE_NAME="sms-api"
RETRY_JOB_NAME="sms-retry-queue-worker"
BALANCE_JOB_NAME="provider-balance-refresh"

if [ -z "${PROJECT_ID}" ]; then
    echo "No active gcloud project. Run: gcloud config set project nola-sms-pro" >&2
    exit 1
fi

if [ -z "${CRON_SECRET:-}" ]; then
    echo "CRON_SECRET is required. Export the same value configured in Cloud Run before running this script." >&2
    exit 1
fi

SERVICE_URL=$(gcloud run services describe "$SERVICE_NAME" \
    --region="$REGION" \
    --project="$PROJECT_ID" \
    --format="value(status.url)")

RETRY_TARGET_URL="${SERVICE_URL}/api/retry_sms_queue.php"
BALANCE_TARGET_URL="${SERVICE_URL}/api/admin/provider-balance-refresh-cron"

upsert_http_job() {
    local job_name="$1"
    local schedule="$2"
    local target_url="$3"
    local description="$4"

    if gcloud scheduler jobs describe "$job_name" --location="$REGION" --project="$PROJECT_ID" >/dev/null 2>&1; then
        action="update"
    else
        action="create"
    fi

    gcloud scheduler jobs "$action" http "$job_name" \
        --location="$REGION" \
        --project="$PROJECT_ID" \
        --schedule="$schedule" \
        --uri="$target_url" \
        --http-method="POST" \
        --headers="X-Cron-Secret=${CRON_SECRET},Content-Type=application/json" \
        --message-body="{}" \
        --time-zone="Asia/Manila" \
        --attempt-deadline="60s" \
        --description="$description"

    echo "Cloud Scheduler ${action}d: ${job_name}"
    echo "Target: ${target_url}"
}

upsert_http_job "$RETRY_JOB_NAME" "*/5 * * * *" "$RETRY_TARGET_URL" "SMS Retry Queue Worker - re-sends timed-out messages"
upsert_http_job "$BALANCE_JOB_NAME" "*/15 * * * *" "$BALANCE_TARGET_URL" "Provider balance refresh - updates admin dashboard summary outside page loads"
