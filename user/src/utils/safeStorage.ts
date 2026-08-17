/**
 * Safe wrapper around localStorage.
 * In environments where localStorage is blocked (e.g. GHL iframe with 3rd-party cookies disabled in Incognito),
 * calling localStorage.getItem or setItem throws a DOMException.
 * This wrapper falls back to an in-memory store to prevent the app from crashing (white screen).
 */

class SafeStorage {
  private memoryStore: Record<string, string> = {};

  private isLocalStorageAvailable(): boolean {
    try {
      const test = '__storage_test__';
      localStorage.setItem(test, test);
      localStorage.removeItem(test);
      return true;
    } catch {
      return false;
    }
  }

  private hasStorage = this.isLocalStorageAvailable();

  public getItem(key: string): string | null {
    if (this.hasStorage) {
      try {
        const val = localStorage.getItem(key);
        // If localStorage is empty/blocked but we have an in-memory fallback from a failed setItem, use it!
        if (val === null && this.memoryStore[key] !== undefined) {
            return this.memoryStore[key];
        }
        return val;
      } catch {
        return this.memoryStore[key] || null;
      }
    }
    return this.memoryStore[key] || null;
  }

  public setItem(key: string, value: string): void {
    // Always keep memoryStore strictly in sync
    this.memoryStore[key] = value;
    if (this.hasStorage) {
      try {
        localStorage.setItem(key, value);
      } catch {
        // Silently caught, memoryStore is already set
      }
    }
  }

  public removeItem(key: string): void {
    delete this.memoryStore[key];
    if (this.hasStorage) {
      try {
        localStorage.removeItem(key);
      } catch {
        // Silently caught
      }
    }
  }
}

export const safeStorage = new SafeStorage();
