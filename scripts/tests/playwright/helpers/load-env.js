import fs from 'fs';
import path from 'path';

/**
 * Parse the repo root .env file and return a key/value object.
 * Keys read: DOLIBARR_URL, DOLIBARR_ADMIN_USER, DOLIBARR_ADMIN_PASS,
 *            DB_HOST, DB_NAME, DB_USER, DB_PASS.
 * Never logs values — the object is used in-process only.
 */
export function loadEnv() {
  const envPath = path.resolve(import.meta.dirname, '../../../..', '.env');
  if (!fs.existsSync(envPath)) {
    throw new Error(`.env not found at ${envPath}`);
  }
  const result = {};
  for (const line of fs.readFileSync(envPath, 'utf8').split('\n')) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq < 1) continue;
    const key = trimmed.slice(0, eq).trim();
    const val = trimmed.slice(eq + 1).trim().replace(/^["']|["']$/g, '');
    result[key] = val;
  }
  return result;
}
