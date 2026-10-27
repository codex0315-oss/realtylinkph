/**
 * Helpers for the 360° virtual tour (Property360Viewer).
 *
 * WebGL refuses a cross-origin texture unless the server sends CORS headers.
 *  - served by our own backend (`<api host>/storage/…`) → strip the origin so
 *    it loads through this app's /storage proxy, i.e. same-origin.
 *  - served by a bucket (Supabase/S3/R2) → used as-is; the bucket's CORS
 *    headers allow it (Supabase sends `*`; see DEPLOYMENT.md).
 *
 * The decision is made on the HOST, not on the path: Supabase URLs also
 * contain "/storage/" (`…supabase.co/storage/v1/object/…`), and rewriting
 * those onto this app's origin broke every bucket-hosted panorama.
 */
export function panoramaUrl(url: string): string {
  let api: URL | null = null
  try { api = new URL(useRuntimeConfig().public.apiBase as string) } catch { /* no API base */ }
  try {
    const u = new URL(url, window.location.href)
    if (api && isApiHost(u, api) && u.pathname.startsWith('/storage/')) return u.pathname + u.search
  } catch { /* not a URL — hand it to the viewer untouched */ }
  return url
}

/* localhost and 127.0.0.1 are the same machine: in development the API base is
   one and the photo URLs (APP_URL) are often the other. Treating them as
   different sent panoramas cross-origin, where the local server refuses them. */
const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]'])
function isApiHost(u: URL, api: URL): boolean {
  if (u.host === api.host) return true
  return LOOPBACK.has(u.hostname) && LOOPBACK.has(api.hostname) && u.port === api.port
}

/*
 * Panoramas downloaded this visit, by URL. The viewer is handed the same
 * Blob instead of fetching again, so a background download is never wasted,
 * whatever caching headers the storage sends.
 */
const panoramas = new Map<string, Promise<Blob | null>>()

/** Download a panorama once; later calls share the same download. Null on failure. */
export function fetchPanorama(src: string): Promise<Blob | null> {
  const url = panoramaUrl(src)
  let p = panoramas.get(url)
  if (!p) {
    p = fetch(url, { credentials: 'same-origin' })
      .then(r => (r.ok ? r.blob() : null))
      .catch(() => null)
    panoramas.set(url, p)
    // A failed download may be retried next time.
    p.then(b => { if (!b) panoramas.delete(url) })
  }
  return p
}
