/**
 * The key a browser keeps after an admin ticks "Trust this device". It only
 * works together with the right password (or Google), and the server forgets it
 * after 30 days or when the password changes. Reading and writing never throws:
 * in a private window there is simply nothing to remember.
 */
const DEVICE_KEY = 'rl-admin-device'

export function readDeviceKey(): string | undefined {
  try { return localStorage.getItem(DEVICE_KEY) || undefined } catch { return undefined }
}

export function saveDeviceKey(key: string): void {
  try { localStorage.setItem(DEVICE_KEY, key) } catch { /* nothing to remember in a private window */ }
}
