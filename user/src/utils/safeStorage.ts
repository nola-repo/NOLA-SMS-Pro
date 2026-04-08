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
    } catch (e) {
      return false;
    }
  }

  private hasStorage = this.isLocalStorageAvailable();

  public getItem(key: string): string | null {
    if (this.hasStorage) {
      try {
        return localStorage.getItem(key);
      } catch {
        return this.memoryStore[key] || null;
      }
    }
    return this.memoryStore[key] || null;
  }

  public setItem(key: string, value: string): void {
    if (this.hasStorage) {
      try {
        localStorage.setItem(key, value);
      } catch {
        this.memoryStore[key] = value;
      }
    } else {
      this.memoryStore[key] = value;
    }
  }

  public removeItem(key: string): void {
    if (this.hasStorage) {
      try {
        localStorage.removeItem(key);
      } catch {
        delete this.memoryStore[key];
      }
    } else {
      delete this.memoryStore[key];
    }
  }
}

export const safeStorage = new SafeStorage();
