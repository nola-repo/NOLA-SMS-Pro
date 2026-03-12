# NOLA SMS Pro

NOLA SMS Pro is a powerful SMS management system integrated with GoHighLevel (GHL).

## Quick Links

- [GHL Setup Guide](GHL-SETUP.md) - How to connect the app to your GHL subaccount.
- [Handoff Guide (Backend)](frontend/backend_handoff.md) - Technical requirements for the backend team.
- [Deployment Guide (Cloud Run)](DEPLOY-CLOUDRUN.md) - How to build and deploy the API.

## Project Structure

- `/api`: PHP Backend API (Cloud Run).
- `/frontend`: React + TypeScript Frontend (Vite).

## Getting Started

### Local Development (Frontend)
```bash
cd frontend
npm install
npm run dev
```

### Deployment
Refer to [DEPLOY-CLOUDRUN.md](DEPLOY-CLOUDRUN.md) for backend deployment and standard Vercel flow for frontend.
