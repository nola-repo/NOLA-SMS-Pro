/**
 * sessionSafeStorage.ts
 * Safe wrapper around sessionStorage for auth tokens.
 *
 * WHY sessionStorage instead of localStorage for tokens:
 *   - Tokens stored in localStorage persist indefinitely — any XSS payload or
 *     malicious browser extension can exfiltrate them long after the session ends.
 *   - sessionStorage is scoped to the tab and cleared automatically when the tab
 *     or browser is closed, drastically reducing the XSS token-theft window.
 *   - Falls back to an in-memory store (same as safeStorage) when sessionStorage
 *     is blocked (e.g., GHL iframe with strict cookie policies in Incognito).
 */

class SessionSafeStorage {
  private memoryStore: Record<string, string> = {};

  private isSessionStorageAvailable(): boolean {
    try {
      const test = '__session_storage_test__';
      sessionStorage.setItem(test, test);
      sessionStorage.removeItem(test);
      return true;
    } catch {
      return false;
    }
  }

  private hasStorage = this.isSessionStorageAvailable();

  public getItem(key: string): string | null {
    if (this.hasStorage) {
      try {
        const val = sessionStorage.getItem(key);
        if (val === null && this.memoryStore[key] !== undefined) {
          return this.memoryStore[key];
        }
        return val;
      } catch {
        return this.memoryStore[key] ?? null;
      }
    }
    return this.memoryStore[key] ?? null;
  }

  public setItem(key: string, value: string): void {
    this.memoryStore[key] = value;
    if (this.hasStorage) {
      try {
        sessionStorage.setItem(key, value);
      } catch {
        // Silently fall through — memoryStore already updated
      }
    }
  }

  public removeItem(key: string): void {
    delete this.memoryStore[key];
    if (this.hasStorage) {
      try {
        sessionStorage.removeItem(key);
      } catch {
        // Silently caught
      }
    }
  }
}

export const sessionSafeStorage = new SessionSafeStorage();
