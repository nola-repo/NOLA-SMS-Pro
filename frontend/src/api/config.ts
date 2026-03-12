/**
 * API Configuration
 *
 * In development, Vite proxies handle /api/* requests (see vite.config.ts).
 * In production (Cloud Run), we call the backend directly.
 */

// Backend Cloud Run URL — used in production builds
const PRODUCTION_API_URL = "https://smspro-api.nolacrm.io";

// In dev mode (Vite), use empty string so relative /api/* paths hit the Vite proxy.
// In production, use the backend URL.
export const API_BASE_URL: string =
  import.meta.env.VITE_API_BASE_URL ||
  (import.meta.env.DEV ? "" : PRODUCTION_API_URL);

// Shared secret for backend authentication
export const WEBHOOK_SECRET = "f7RkQ2pL9zV3tX8cB1nS4yW6";
