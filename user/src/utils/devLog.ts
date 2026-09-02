/**
 * devLog.ts
 * Conditional logging utility — completely silent in production builds.
 * Use instead of bare console.log/warn/error throughout the codebase
 * so sensitive API errors and internal route names are never exposed
 * in the browser DevTools of production users.
 *
 * Usage:
 *   import { devLog } from '../utils/devLog';
 *   devLog.error('Some error', err);   // only prints in DEV mode
 *   devLog.warn('Warning', details);
 */

const isDev = import.meta.env.DEV;

export const devLog = {
  /** Equivalent of console.log — dev only */
  log: (...args: unknown[]): void => {
    if (isDev) console.log(...args);
  },

  /** Equivalent of console.warn — dev only */
  warn: (...args: unknown[]): void => {
    if (isDev) console.warn(...args);
  },

  /** Equivalent of console.error — dev only */
  error: (...args: unknown[]): void => {
    if (isDev) console.error(...args);
  },
};
