const meta = import.meta as ImportMeta & { env?: { DEV?: boolean } };
const isDev = Boolean(meta.env?.DEV);

export const devLog = {
  log: (...args: unknown[]) => {
    if (isDev) console.log(...args);
  },
  warn: (...args: unknown[]) => {
    if (isDev) console.warn(...args);
  },
  error: (...args: unknown[]) => {
    if (isDev) console.error(...args);
  },
};