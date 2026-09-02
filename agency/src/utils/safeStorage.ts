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
        const val = localStorage.getItem(key);
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
    this.memoryStore[key] = value;
    if (this.hasStorage) {
      try {
        localStorage.setItem(key, value);
      } catch {
        // memoryStore is already set
      }
    } else {
      this.memoryStore[key] = value;
    }
  }

  public removeItem(key: string): void {
    delete this.memoryStore[key];
    if (this.hasStorage) {
      try {
        localStorage.removeItem(key);
      } catch {
        // memoryStore is already cleared
      }
    }
  }
}

export const safeStorage = new SafeStorage();
