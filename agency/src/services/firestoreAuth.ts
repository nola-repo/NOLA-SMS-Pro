import { signInWithCustomToken } from 'firebase/auth';
import { auth } from './firebaseConfig';
import { getAgencySession } from './agencyAuthHelper';
import { apiFetch } from '../utils/apiFetch';

let inflight: Promise<void> | null = null;
let signedForAppToken: string | null = null;

export const ensureFirestoreAuth = async (): Promise<void> => {
  const session = getAgencySession();
  const appToken = session?.token;
  if (!appToken) {
    throw new Error('Agency session is required for realtime updates.');
  }

  if (auth.currentUser && !auth.currentUser.isAnonymous && signedForAppToken === appToken) {
    return;
  }

  if (inflight) return inflight;

  inflight = (async () => {
    const response = await apiFetch('/api/auth/firebase_token.php', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${appToken}`,
        Accept: 'application/json',
      },
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.token) {
      throw new Error(data.error || 'Could not start realtime session.');
    }

    await signInWithCustomToken(auth, data.token);
    signedForAppToken = appToken;
  })().finally(() => {
    inflight = null;
  });

  return inflight;
};