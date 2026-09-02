# Backend Handoff: Decoupled Auth Architecture

**Date:** April 2026  
**Component:** Frontend Authentication & Domain Routing  
**Target Audience:** NOLA SMS Pro Backend (PHP) Team  

## Overview
The frontend architecture was recently restructured to isolate the **Agency Portal** and the **User (Sub-account) Portal** into entirely separate subdomains:
- **Agency Domain:** `https://agency.nolasmspro.com`
- **User Domain:** `https://app.nolasmspro.com`

Because LocalStorage and browser state are securely isolated across subdomains, the frontend login mechanism has been decoupled. **However, your existing PHP backend APIs do not require any changes.** This document simply clarifies how the frontend interacts with your endpoints.

---

## 1. Registration Flow (`Centralized`)
To provide the best marketing experience, we implemented a **Centralized Registration** approach.
- **Frontend Entry:** `https://app.nolasmspro.com/register`
- **Backend API:** `POST /api/auth/register.php`

**How it works:**
The frontend displays a "Choose Role" (Agency vs User) UI component. When submitted, the frontend securely passes the chosen `role` string directly to the existing `register.php` endpoint. 

The backend successfully provisions the account with the correct role. **No changes to `register.php` are required.**

---

## 2. Login Flow (`Decentralized`)
Because the agency and user frontends exist on isolated subdomains, they must maintain their own distinct WebStorage sessions.
- **Backend API:** `POST /api/auth/login.php`

**What changed on the frontend:**
If a user goes to `app.nolasmspro.com/login` and logs in with an `agency` role, the frontend will intentionally **reject** the login (deleting the token from LocalStorage) and display an error guiding the user to the correct domain (`https://agency.nolasmspro.com/login`). 

**What this means for the backend:**
The login API remains perfectly intact. The backend `/login.php` endpoint continues to validate credentials and return the JWT payload containing `role`, `company_id`, etc. The frontend's React components are strictly handling the role-based domain routing securely.

---

## 3. GoHighLevel "No-Login" Sub-account Bypass
This is a critical feature enabling instant access for sub-accounts loaded within the GoHighLevel iframe.

**How it works:**
If the frontend detects `location_id` (or `sessionkey`) in the active URL parameters (which GoHighLevel automatically appends), the frontend's Auth Guard **bypasses the standard `/login` route requirement** and instantly boots the dashboard.

**Security Context:**
Since the React frontend effectively permits access *without* a persistent JWT when inside a GoHighLevel iframe:
1. Contact fetching, SMS sending, and other core features rely on your proxy endpoints reading the `X-GHL-Location-ID` header and validating against GoHighLevel.
2. Ensure that any sensitive operations on the PHP backend explicitly require a valid Session/Token *outside* of standard verified GHL iframe requests. 

**Summary:** You do not need to update any database schemas or role endpoints. The frontend is perfectly adapting to the `role` values your REST endpoints return.
