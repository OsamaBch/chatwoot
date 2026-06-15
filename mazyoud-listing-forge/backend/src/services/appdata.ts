import os from 'node:os';
import path from 'node:path';

/**
 * Per-OS application data directory (OUTSIDE the repo), used for secrets +
 * settings. Because it lives in the user's home area, it is never committed.
 */
export function appDataDir(): string {
  const home = os.homedir();
  switch (process.platform) {
    case 'darwin':
      return path.join(home, 'Library', 'Application Support', 'MazyoudListingForge');
    case 'win32':
      return path.join(process.env.APPDATA || path.join(home, 'AppData', 'Roaming'), 'MazyoudListingForge');
    default:
      return path.join(process.env.XDG_CONFIG_HOME || path.join(home, '.config'), 'mazyoud-listing-forge');
  }
}
