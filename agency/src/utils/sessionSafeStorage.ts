/**
 * sessionSafeStorage.ts (agency)
 * Safe wrapper around sessionStorage for auth tokens.
 * Mirror of user/src/utils/sessionSafeStorage.ts
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
