/**
 * Shared setup for the MapLibre maps (browse map, listing map, location picker).
 *
 * Styles: MapTiler when NUXT_PUBLIC_MAPTILER_KEY is set (streets, dark streets
 * and satellite), otherwise OpenFreeMap (free, no key, no satellite). The
 * library and its CSS load only on pages that show a map.
 */
export type MapBase = 'streets' | 'satellite'

/** Cebu City: where a map starts when there's nothing to fit yet. */
export const CEBU_CENTER: [number, number] = [123.8854, 10.3157]

let libPromise: Promise<typeof import('maplibre-gl')> | null = null

export function useMapLibre() {
  const key = useRuntimeConfig().public.maptilerKey as string
  const hasSatellite = !!key
  const dark = ref(false)

  function styleUrl(base: MapBase, isDark = dark.value): string {
    if (key) {
      if (base === 'satellite') return `https://api.maptiler.com/maps/hybrid/style.json?key=${key}`
      return `https://api.maptiler.com/maps/${isDark ? 'streets-v2-dark' : 'streets-v2'}/style.json?key=${key}`
    }
    return `https://tiles.openfreemap.org/styles/${isDark ? 'dark' : 'liberty'}`
  }

  async function load() {
    if (!libPromise) {
      libPromise = Promise.all([import('maplibre-gl'), import('maplibre-gl/dist/maplibre-gl.css')]).then(([m]) => m)
    }
    return libPromise
  }

  /** Follow the site's light/dark theme (the `dark` class on <html>). */
  function watchTheme(onChange: (isDark: boolean) => void): () => void {
    if (!import.meta.client) return () => {}
    const read = () => document.documentElement.classList.contains('dark')
    dark.value = read()
    const obs = new MutationObserver(() => {
      const d = read()
      if (d !== dark.value) { dark.value = d; onChange(d) }
    })
    obs.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
    return () => obs.disconnect()
  }

  /** The map credit starts open and covers the bottom of small maps: start it folded into its ⓘ button. */
  function foldAttribution(map: import('maplibre-gl').Map) {
    const fold = () => map.getContainer().querySelector('.maplibregl-ctrl-attrib')?.classList.remove('maplibregl-compact-show')
    map.once('load', fold)
    map.on('styledata', fold)
  }

  return { hasSatellite, dark, styleUrl, load, watchTheme, foldAttribution }
}
