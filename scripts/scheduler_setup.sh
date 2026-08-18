#!/bin/bash
set -euo pipefail

# Cloud Scheduler: SMS Retry Queue Worker
# Creates or updates a Cloud Scheduler job that hits /api/retry_sms_queue.php
# every 5 minutes.
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
JOB_NAME="sms-retry-queue-worker"

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

TARGET_URL="${SERVICE_URL}/api/retry_sms_queue.php"

if gcloud scheduler jobs describe "$JOB_NAME" --location="$REGION" --project="$PROJECT_ID" >/dev/null 2>&1; then
    ACTION="update"
else
    ACTION="create"
fi

gcloud scheduler jobs "$ACTION" http "$JOB_NAME" \
    --location="$REGION" \
    --project="$PROJECT_ID" \
    --schedule="*/5 * * * *" \
    --uri="$TARGET_URL" \
    --http-method="POST" \
    --headers="X-Cron-Secret=${CRON_SECRET},Content-Type=application/json" \
    --message-body="{}" \
    --time-zone="Asia/Manila" \
    --attempt-deadline="60s" \
    --description="SMS Retry Queue Worker - re-sends timed-out messages every 5 minutes"

echo "Cloud Scheduler ${ACTION}d: ${JOB_NAME}"
echo "Target: ${TARGET_URL}"
