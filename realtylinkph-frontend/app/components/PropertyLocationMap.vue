<script setup lang="ts">
import type { MapBase } from '~/composables/useMapLibre'
import { NEARBY_ICON } from '~/composables/useNearby'
import type { Nearby } from '~/composables/useNearby'

/**
 * The listing page's map (MapLibre): the home's pin, plus the nearest
 * hospital, school, mall and other essentials as small icons (the same data
 * as "Getting around", already cached). Satellite view with a MapTiler key;
 * follows dark mode. Two fingers / Ctrl + scroll to move, so the page still
 * scrolls normally.
 */
const props = defineProps<{
  lat?: number | string | null
  lng?: number | string | null
  address?: string
  /** Draws nearby essentials when given (published listings). */
  propertyId?: number
}>()

const { geocode } = useGeocode()
const { fetchNearby } = useNearby()
const { hasSatellite, styleUrl, load, watchTheme, foldAttribution } = useMapLibre()
const mapEl  = ref<HTMLElement | null>(null)
const failed = ref(false)
const base   = ref<MapBase>('streets')
const nearby = ref<Nearby | null>(null)
let map: import('maplibre-gl').Map | null = null
let stopTheme: (() => void) | null = null

/** The nearest place of the six kinds buyers ask about most keeps the map readable (the full list is under "Getting around"). */
const MAP_KINDS = ['hospital', 'school', 'mall', 'grocery', 'pharmacy', 'transit']
const places = computed(() => (nearby.value?.kinds ?? [])
  .filter(k => MAP_KINDS.includes(k.key))
  .flatMap(k => k.items.slice(0, 1).map(p => ({ ...p, key: k.key, label: k.label }))))

async function resolveCoords(): Promise<{ lat: number; lng: number } | null> {
  if (props.lat && props.lng) return { lat: Number(props.lat), lng: Number(props.lng) }
  if (props.address) return await geocode(props.address)
  return null
}

function esc(s: unknown): string {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c]!))
}

async function init() {
  if (!import.meta.client || !mapEl.value) return
  const coords = await resolveCoords()
  if (!coords) { failed.value = true; return }
  const ml = await load()
  const home: [number, number] = [coords.lng, coords.lat]

  // Read the theme before the map is made, so a dark page starts with the dark map.
  stopTheme = watchTheme(() => { if (base.value === 'streets') map?.setStyle(styleUrl('streets'), { diff: false }) })
  map = new ml.Map({
    container: mapEl.value,
    style: styleUrl(base.value),
    center: home,
    zoom: 15,
    attributionControl: { compact: true },
    cooperativeGestures: true,
    dragRotate: false,
  })
  map.addControl(new ml.NavigationControl({ showCompass: false }), 'top-right')
  foldAttribution(map)

  const pin = document.createElement('div')
  pin.className = 'prop-loc-pin'
  pin.innerHTML = `<svg width="40" height="40" viewBox="0 0 24 24" fill="#D4A54A" stroke="#0B2A5C" stroke-width="1.4" aria-hidden="true"><path d="M12 21s-7-7.5-7-12a7 7 0 1114 0c0 4.5-7 12-7 12z"/><circle cx="12" cy="9" r="2.6" fill="#0B2A5C"/></svg>`
  new ml.Marker({ element: pin, anchor: 'bottom' }).setLngLat(home).addTo(map)


  if (props.propertyId) {
    nearby.value = await fetchNearby(props.propertyId)
    if (!map) return
    const bounds = new ml.LngLatBounds(home, home)
    for (const p of places.value) {
      const el = document.createElement('div')
      el.className = 'nearby-dot'
      el.title = `${p.label}: ${p.name}`
      el.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${NEARBY_ICON[p.key] ?? NEARBY_ICON.hospital}"/></svg>`
      const pop = new ml.Popup({ offset: 16, closeButton: false, className: 'nearby-pop' })
        .setHTML(`<p class="np-kind">${esc(p.label)}</p><p class="np-name">${esc(p.name)}</p>${p.drive_min ? `<p class="np-min">${p.drive_min} min by car</p>` : ''}`)
      new ml.Marker({ element: el }).setLngLat([p.lng, p.lat]).setPopup(pop).addTo(map)
      bounds.extend([p.lng, p.lat])
    }
    if (places.value.length) map.fitBounds(bounds, { padding: 48, maxZoom: 15, duration: 0 })
  }
}

function setBase(b: MapBase) {
  if (!map || base.value === b) return
  base.value = b
  map.setStyle(styleUrl(b), { diff: false })
}

onMounted(init)
onBeforeUnmount(() => { stopTheme?.(); map?.remove(); map = null })
</script>

<template>
  <div class="relative w-full h-72 sm:h-80 rounded-2xl overflow-hidden border border-gray-200 dark:border-white/10">
    <div ref="mapEl" class="w-full h-full z-0" />
    <div v-if="hasSatellite && !failed" class="absolute left-3 top-3 z-10 inline-flex rounded-full bg-white/95 p-1 shadow-md ring-1 ring-black/5 dark:bg-[#0B1A35]/95 dark:ring-white/10" role="radiogroup" aria-label="Map type">
      <button
        v-for="b in (['streets', 'satellite'] as const)"
        :key="b"
        type="button"
        role="radio"
        :aria-checked="base === b"
        class="rounded-full px-3 py-1 text-xs font-semibold transition"
        :class="base === b ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/70 hover:text-brand-navy dark:text-white/70'"
        @click="setBase(b)"
      >{{ b === 'streets' ? 'Map' : 'Satellite' }}</button>
    </div>
    <p v-if="places.length" class="absolute bottom-2 left-2 z-10 rounded-full bg-white/90 px-2.5 py-1 text-[0.625rem] font-semibold text-brand-navy shadow ring-1 ring-black/5 dark:bg-[#0B1A35]/90 dark:text-white">
      Icons: nearest essentials · tap one for details
    </p>
    <div v-if="failed" class="absolute inset-0 flex flex-col items-center justify-center bg-gray-50 dark:bg-white/5 text-center px-6 pointer-events-none">
      <svg class="h-8 w-8 text-gray-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
      <p class="text-sm text-gray-400">Map location unavailable.</p>
      <p v-if="address" class="text-xs text-gray-400 mt-1">{{ address }}</p>
    </div>
  </div>
</template>

<style>
.prop-loc-pin { filter: drop-shadow(0 4px 6px rgba(0,0,0,0.35)); cursor: default; z-index: 3; }
.nearby-dot {
  display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 9999px;
  background: #fff; color: #0B2A5C; border: 2px solid #0B2A5C; box-shadow: 0 3px 8px rgba(0,0,0,0.25); cursor: pointer;
}
.nearby-dot svg { width: 15px; height: 15px; }
.nearby-pop .maplibregl-popup-content { padding: 8px 11px; border-radius: 12px; font-family: inherit; box-shadow: 0 10px 24px -10px rgba(6,24,58,0.45); }
.np-kind { margin: 0; font-size: 9.5px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(11,42,92,0.5); }
.np-name { margin: 2px 0 0; font-size: 12.5px; font-weight: 700; color: #0B2A5C; }
.np-min { margin: 2px 0 0; font-size: 11px; color: #85601A; font-weight: 700; }
</style>
