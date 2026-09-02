/**
 * firebaseConfig.ts
 * Initialises the Firebase app for the Agency Panel (singleton).
 * Exports:
 *   db   — Firestore instance (used by the onSnapshot real-time listener)
 *   auth — Firebase Auth instance (used for anonymous sign-in, Option B)
 *
 * All config values come from VITE_ env vars so the build is environment-agnostic.
 * Firebase Web API keys are intentionally public — security is enforced via
 * Firestore Security Rules (require request.auth != null for ghl_tokens reads).
 */

import { initializeApp, getApps } from 'firebase/app';
import { getFirestore } from 'firebase/firestore';
import { getAuth } from 'firebase/auth';

const firebaseConfig = {
  apiKey:            import.meta.env.VITE_FIREBASE_API_KEY,
  authDomain:        import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
  projectId:         import.meta.env.VITE_FIREBASE_PROJECT_ID,
  storageBucket:     import.meta.env.VITE_FIREBASE_STORAGE_BUCKET,
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
  appId:             import.meta.env.VITE_FIREBASE_APP_ID,
};

// Avoid re-initialising if HMR re-runs this module
const app = getApps().length ? getApps()[0] : initializeApp(firebaseConfig);

export const db   = getFirestore(app);
export const auth = getAuth(app);
