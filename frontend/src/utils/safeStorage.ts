class SafeStorage {
  private fallbackMap = new Map<string, string>();

  public getItem(key: string): string | null {
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        return window.localStorage.getItem(key);
      }
    } catch (e) {
      console.warn('localStorage is disabled or blocked, using in-memory fallback.');
    }
    return this.fallbackMap.get(key) || null;
  }

  public setItem(key: string, value: string): void {
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        window.localStorage.setItem(key, value);
        return;
      }
    } catch (e) {
      console.warn('localStorage is disabled or blocked, using in-memory fallback.');
    }
    this.fallbackMap.set(key, value);
  }

  public removeItem(key: string): void {
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        window.localStorage.removeItem(key);
        return;
      }
    } catch (e) {
      console.warn('localStorage is disabled or blocked, using in-memory fallback.');
    }
    this.fallbackMap.delete(key);
  }

  public clear(): void {
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        window.localStorage.clear();
        return;
      }
    } catch (e) {
      console.warn('localStorage is disabled or blocked, using in-memory fallback.');
    }
    this.fallbackMap.clear();
  }
}

export const safeStorage = new SafeStorage();
