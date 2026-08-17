# End-to-End Staging Deployment & Git Branching Guide (Frontend & Backend)

This document provides a complete guide for setting up and managing a isolated **Staging Environment** across Google Cloud Run and GitHub for both **Frontend** (`nola-sms-pro-frontend`) and **Backend** (`nola-sms-pro-backend`) so you can test features inside GoHighLevel without affecting active users.

---

## 🏗️ Architecture Overview

```mermaid
flowchart TD
    subgraph GitHub ["GitHub Repositories"]
        FE_Main["Frontend (main)"]
        FE_Staging["Frontend (staging)"]
        BE_Main["Backend (main)"]
        BE_Staging["Backend (staging)"]
    end

    subgraph CloudBuild ["Google Cloud Build"]
        FE_Prod_Trigger["Trigger: Frontend Production<br/>(on push to main)"]
        FE_Stage_Trigger["Trigger: Frontend Staging<br/>(on push to staging)"]
        BE_Prod_Trigger["Trigger: Backend Production<br/>(on push to main)"]
        BE_Stage_Trigger["Trigger: Backend Staging<br/>(on push to staging)"]
    end

    subgraph CloudRun ["Google Cloud Run Services"]
        FE_Prod_Run["Service: nolasmspro-frontend<br/>URL: https://app.nolacrm.io"]
        FE_Stage_Run["Service: nolasmspro-frontend-staging<br/>URL: https://staging.nolacrm.io"]
        BE_Prod_Run["Service: sms-api<br/>URL: https://smspro-api.nolacrm.io"]
        BE_Stage_Run["Service: sms-api-staging<br/>URL: https://staging-api.nolacrm.io"]
    end

    subgraph HighLevel ["GoHighLevel Marketplace & Subaccounts"]
        LiveClients["Live Paying Clients<br/>(Opens Production)"]
        TestSubaccount["Internal QA Subaccount<br/>(Opens Staging)"]
    end

    FE_Main --> FE_Prod_Trigger --> FE_Prod_Run --> LiveClients
    BE_Main --> BE_Prod_Trigger --> BE_Prod_Run

    FE_Staging --> FE_Stage_Trigger --> FE_Stage_Run --> TestSubaccount
    BE_Staging --> BE_Stage_Trigger --> BE_Stage_Run
```

---

## 🚀 Part 1: Create `staging` Branches in Git

### 1. Frontend Repository (`nola-sms-pro`)
Run the following in powershell/terminal:
```bash
cd c:\Users\User\nola-sms-pro
git checkout main
git pull origin main
git checkout -b staging
git push -u origin staging
```

### 2. Backend Repository (`nola-sms-pro-backend`)
Run the following in powershell/terminal:
```bash
cd c:\Users\User\nola-sms-pro-backend
git checkout main
git pull origin main
git checkout -b staging
git push -u origin staging
```

---

## ⚙️ Part 2: Set Up Google Cloud Build Triggers

Go to [Google Cloud Console → Cloud Build → Triggers](https://console.cloud.google.com/cloud-build/triggers).

### 1. Frontend Staging Trigger (`nolasmspro-frontend-staging`)
1. Click **Create Trigger** (or **Duplicate** existing frontend trigger):
   - **Name:** `nolasmspro-frontend-staging`
   - **Event:** `Push to a branch`
   - **Source Repository:** `nola-repo/nola-sms-pro-frontend`
   - **Branch:** `^staging$`
   - **Included files filter:** `user/**`
   - **Configuration:** `Cloud Build configuration file (YAML)`
   - **Location:** `Repository` → `user/cloudbuild.yaml`
2. Under **Substitution variables**, set:
   - `_SERVICE_NAME` = `nolasmspro-frontend-staging`
   - `_DEPLOY_REGION` = `asia-southeast1`
   - `_AR_HOSTNAME` = `asia-southeast1-docker.pkg.dev`
   - `_AR_REPOSITORY` = `cloud-run-source-deploy`
   - `_AR_PROJECT_ID` = `nola-sms-pro`
3. Click **Create**.

---

### 2. Backend Staging Trigger (`sms-api-staging`)
1. Click **Create Trigger** (or **Duplicate** existing `sms-api` trigger):
   - **Name:** `sms-api-staging`
   - **Event:** `Push to a branch`
   - **Source Repository:** `nola-sms-pro-backend`
   - **Branch:** `^staging$`
   - **Configuration:** `Cloud Build configuration file (YAML)`
   - **Location:** `Repository` → `cloudbuild.staging.yaml`
2. Click **Create**.

---

## 🔗 Part 3: HighLevel Custom Menu Link Setup

Once Cloud Run deploys the staging frontend:
1. Copy the staging Cloud Run URL (e.g. `https://nolasmspro-frontend-staging-xxxxx-as.a.run.app` or `https://staging.nolacrm.io`).
2. Go to **GoHighLevel Agency Settings → Custom Menu Links**.
3. Click **Add Link**:
   - **Title:** `NOLA SMS Pro (Staging / QA)`
   - **URL:** Your staging URL
   - **Icon:** 🛠️ / 💬
   - **Show on:** **Only in selected locations** → Check **ONLY your internal test location**.
4. Click **Save**.

Now, only you and your QA team can see the Staging icon inside your test subaccount, while your live paying clients only see the production app!

---

## 🔄 Part 4: Daily Developer Workflow

```mermaid
sequenceDiagram
    autonumber
    actor Dev as Developer
    participant Git as GitHub (feature branch)
    participant Staging as Staging (Cloud Run)
    participant GHL as HighLevel Test Subaccount
    participant Prod as Production (Live Users)

    Dev->>Git: git checkout -b feature/new-logic
    Dev->>Git: Write code & git commit
    Dev->>Staging: git checkout staging && git merge feature/... && git push
    Staging-->>GHL: Auto-deploys staging URL
    Dev->>GHL: Test live iframes, buttons, SMS, 2-way replies
    Note over Dev,GHL: Everything confirmed working with 0 errors!
    Dev->>Prod: git checkout main && git merge staging && git push
    Prod-->>Dev: Production deploys safely to live users!
```

### Commands Cheat Sheet

| Step | Action | Git Command |
| :--- | :--- | :--- |
| **1** | Start a new feature or fix | `git checkout -b feature/my-feature` |
| **2** | Commit your progress | `git add .`<br>`git commit -m "feat: description"` |
| **3** | Deploy to Staging | `git checkout staging`<br>`git merge feature/my-feature`<br>`git push origin staging` |
| **4** | Test in HighLevel | Open your test location in GHL and verify live functionality |
| **5** | Release to Production | `git checkout main`<br>`git merge staging`<br>`git push origin main` |
