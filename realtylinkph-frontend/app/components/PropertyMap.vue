<script setup lang="ts">
import type { Property } from '~/types'
import type { Nearby } from '~/composables/useNearby'
import { formatDistance } from '~/composables/useNearby'
import type { MapBase } from '~/composables/useMapLibre'

/**
 * Browse map (MapLibre). Price / photo pins; nearby listings group into a
 * circle with a count that opens up on zoom; a card with the nearest
 * essentials when a pin is tapped; "Search this area" after the buyer moves
 * the map; satellite view when a MapTiler key is set; follows dark mode.
 */
export interface MapBounds { south: number; west: number; north: number; east: number }

const props = defineProps<{
  properties: Property[]
  /** Listing card the buyer is hovering in the list: its pin is lifted. */
  highlightId?: number | null
  /** Results are limited to the map area: keep the view instead of re-fitting. */
  areaActive?: boolean
}>()
const emit = defineEmits<{ searchArea: [bounds: MapBounds] }>()

const propertyHref = usePropertyHref()
const { fetchNearby } = useNearby()
const { hasSatellite, styleUrl, load, watchTheme, foldAttribution } = useMapLibre()
const mapEl = ref<HTMLElement | null>(null)
const base = ref<MapBase>('streets')
const moved = ref(false)

/* ── Pin card ──────────────────────────────────────────────────────────
 * Photo, price, key facts and address, then the three nearest essentials
 * (loaded the first time the card opens). Built as an HTML string, so every
 * listing value is escaped first. */
function esc(s: unknown): string {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c]!))
}
function calm(t: string): string {
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
const HIGHLIGHTS: Array<{ keys: string[]; label: string }> = [
  { keys: ['hospital', 'clinic'],         label: 'Hospital' },
  { keys: ['grocery', 'mall', 'market'],  label: 'Groceries' },
  { keys: ['transit', 'school', 'church'], label: 'Transport' },
]
const SINGULAR: Record<string, string> = {
  hospital: 'Hospital', clinic: 'Clinic', grocery: 'Grocery', mall: 'Mall', market: 'Market',
  transit: 'Transport', school: 'School', church: 'Church',
}
function nearbyRows(n: Nearby | null | 'loading'): string {
  if (n === 'loading') {
    return `<div class="pc-nearby"><p class="pc-eyebrow">Nearby</p>${'<div class="pc-skel"></div>'.repeat(3)}</div>`
  }
  if (!n?.kinds.length) return ''
  const rows = HIGHLIGHTS.map((h) => {
    const k = h.keys.map(key => n.kinds.find(x => x.key === key)).find(Boolean)
    const p = k?.items[0]
    if (!k || !p) return ''
    const label = SINGULAR[k.key] ?? h.label
    return `<div class="pc-row"><span class="pc-row-label">${esc(label)}</span><span class="pc-row-name">${esc(p.name)}</span><span class="pc-row-min">${p.drive_min ? `${p.drive_min} min` : esc(formatDistance(p.distance_m))}</span></div>`
  }).join('')
  return rows ? `<div class="pc-nearby"><p class="pc-eyebrow">Nearest by car</p>${rows}</div>` : ''
}
function priceLabel(p: Property): string {
  const n = Number(p.price)
  if (n >= 1_000_000) return `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
  if (n >= 1_000)     return `₱${(n / 1_000).toFixed(0)}K`
  return `₱${n.toLocaleString('en-PH')}`
}
function cardHtml(p: Property, n: Nearby | null | 'loading'): string {
  const photo = (p.photos?.find(x => !x.is_360) ?? p.photos?.[0])
  const src = photo ? (photo.thumb_url ?? photo.url) : ''
  const facts = [
    p.bedrooms ? `${p.bedrooms} bd` : '',
    p.bathrooms ? `${p.bathrooms} ba` : '',
    Number(p.floor_area) ? `${Number(p.floor_area)} m²` : Number(p.lot_area) ? `${Number(p.lot_area)} m² lot` : '',
  ].filter(Boolean).join(' · ')
  return `<div class="pc">
    ${src ? `<div class="pc-photo" style="background-image:url('${esc(src)}')"><span class="pc-badge">${p.offer_type === 'rent' ? 'For rent' : 'For sale'}</span></div>` : ''}
    <div class="pc-body">
      <p class="pc-price">${esc(priceLabel(p))}${p.offer_type === 'rent' ? '<small>/mo</small>' : ''}</p>
      <p class="pc-title">${esc(calm(p.title))}</p>
      ${facts ? `<p class="pc-facts">${esc(facts)}</p>` : ''}
      <p class="pc-addr">${esc(p.address)}</p>
      ${nearbyRows(n)}
      <a class="pc-cta" href="${esc(propertyHref(p.id))}" data-nav>View details →</a>
    </div>
  </div>`
}
/* Card links are plain <a> tags; route them through the app instead of a full reload. */
function onMapClick(e: MouseEvent) {
  const a = (e.target as HTMLElement).closest('a[data-nav]') as HTMLAnchorElement | null
  if (!a) return
  e.preventDefault()
  navigateTo(a.getAttribute('href')!)
}

/* ── Map ── */
type ML = typeof import('maplibre-gl')
let ml: ML | null = null
let map: import('maplibre-gl').Map | null = null
let popup: import('maplibre-gl').Popup | null = null
const markers = new Map<string, import('maplibre-gl').Marker>()
let stopTheme: (() => void) | null = null

const withCoords = computed(() => props.properties.filter(p => p.lat && p.lng))
const byId = computed(() => new Map(withCoords.value.map(p => [p.id, p])))

function geojson() {
  return {
    type: 'FeatureCollection' as const,
    features: withCoords.value.map(p => ({
      type: 'Feature' as const,
      geometry: { type: 'Point' as const, coordinates: [Number(p.lng), Number(p.lat)] },
      properties: { id: p.id },
    })),
  }
}

/** The listings source + an invisible layer (a source only loads tiles when a layer uses it). Re-added after a style change. */
function addSource() {
  if (!map || map.getSource('listings')) return
  map.addSource('listings', { type: 'geojson', data: geojson(), cluster: true, clusterRadius: 48, clusterMaxZoom: 20 })
  map.addLayer({ id: 'listings-hit', type: 'circle', source: 'listings', paint: { 'circle-radius': 1, 'circle-opacity': 0 } })
}

function pinEl(p: Property): HTMLElement {
  const el = document.createElement('div')
  const photo = p.photos?.find(x => !x.is_360) ?? p.photos?.[0]
  const src = photo ? (photo.thumb_url ?? photo.url) : ''
  el.className = 'rl-pin'
  el.innerHTML = src
    ? `<div class="map-photo-pin"><div class="map-photo-pin__img" style="background-image:url('${esc(src)}')"></div><div class="map-photo-pin__price">${esc(priceLabel(p))}</div></div>`
    : `<div class="map-price-pin">${esc(priceLabel(p))}</div>`
  el.setAttribute('role', 'button')
  el.setAttribute('aria-label', `${calm(p.title)}, ${priceLabel(p)}`)
  el.addEventListener('click', (e) => { e.stopPropagation(); openCard(p) })
  return el
}

function clusterEl(count: number, clusterId: number, lngLat: [number, number]): HTMLElement {
  const el = document.createElement('button')
  el.type = 'button'
  el.className = `map-cluster ${count >= 10 ? 'is-big' : ''}`
  el.textContent = String(count)
  el.setAttribute('aria-label', `${count} listings here. Zoom in`)
  el.addEventListener('click', async (e) => {
    e.stopPropagation()
    const src = map?.getSource('listings') as import('maplibre-gl').GeoJSONSource | undefined
    if (!src || !map) return
    const zoom = await src.getClusterExpansionZoom(clusterId)
    // Listings at (almost) the same spot, e.g. units in one building, never
    // separate by zooming: list them instead.
    if (zoom > 17 || map.getZoom() >= 16.5) {
      const leaves = await src.getClusterLeaves(clusterId, 20, 0)
      const items = leaves.map(f => byId.value.get((f.properties as { id: number }).id)).filter((p): p is Property => !!p)
      openList(items, lngLat)
      return
    }
    map.easeTo({ center: lngLat, zoom: Math.min(zoom + 0.5, 17) })
  })
  return el
}

/** Draw HTML markers for whatever clusters / single listings are on screen now. */
function syncMarkers() {
  if (!map || !ml || !map.getSource('listings') || !map.isSourceLoaded('listings')) return
  const seen = new Set<string>()
  for (const f of map.querySourceFeatures('listings')) {
    const coords = (f.geometry as GeoJSON.Point).coordinates as [number, number]
    const pr = f.properties as { cluster?: boolean; cluster_id?: number; point_count?: number; id?: number }
    const key = pr.cluster ? `c${pr.cluster_id}` : `p${pr.id}`
    if (seen.has(key)) continue
    seen.add(key)
    if (markers.has(key)) continue
    let el: HTMLElement | null = null
    if (pr.cluster) el = clusterEl(pr.point_count ?? 0, pr.cluster_id!, coords)
    else {
      const p = byId.value.get(pr.id!)
      if (p) el = pinEl(p)
    }
    if (!el) continue
    markers.set(key, new ml.Marker({ element: el, anchor: 'center' }).setLngLat(coords).addTo(map))
  }
  for (const [key, m] of markers) {
    if (!seen.has(key)) { m.remove(); markers.delete(key) }
  }
  applyHighlight()
}

function clearMarkers() {
  markers.forEach(m => m.remove())
  markers.clear()
}

function applyHighlight() {
  for (const [key, m] of markers) m.getElement().classList.toggle('is-hot', key === `p${props.highlightId}`)
}

/** Several listings at one spot: a short list; tapping one opens its card. */
function openList(items: Property[], lngLat: [number, number]) {
  if (!map || !ml) return
  popup?.remove()
  const rows = items.map(p => `<button type="button" class="pl-row" data-pid="${p.id}"><span class="pl-title">${esc(calm(p.title))}</span><span class="pl-price">${esc(priceLabel(p))}${p.offer_type === 'rent' ? '/mo' : ''}</span></button>`).join('')
  const pop = new ml.Popup({ offset: 24, maxWidth: '260px', className: 'rl-pin-popup', focusAfterOpen: false })
    .setLngLat(lngLat)
    .setHTML(`<div class="pl"><p class="pc-eyebrow">${items.length} listings here</p>${rows}</div>`)
    .addTo(map)
  pop.getElement().addEventListener('click', (e) => {
    const b = (e.target as HTMLElement).closest('[data-pid]') as HTMLElement | null
    const p = b ? byId.value.get(Number(b.dataset.pid)) : undefined
    if (p) openCard(p)
  })
  popup = pop
}

async function openCard(p: Property) {
  if (!map || !ml) return
  popup?.remove()
  const pop = new ml.Popup({ offset: 34, maxWidth: '260px', className: 'rl-pin-popup', focusAfterOpen: false })
    .setLngLat([Number(p.lng), Number(p.lat)])
    .setHTML(cardHtml(p, 'loading'))
    .addTo(map)
  popup = pop
  const n = await fetchNearby(p.id)
  if (popup === pop && pop.isOpen()) pop.setHTML(cardHtml(p, n))
}

function fit() {
  if (!map || !ml || props.areaActive) return
  const pts = withCoords.value.map(p => [Number(p.lng), Number(p.lat)] as [number, number])
  if (!pts.length) return
  if (pts.length === 1) { map.jumpTo({ center: pts[0]!, zoom: 14 }); return }
  const b = new ml.LngLatBounds(pts[0]!, pts[0]!)
  pts.forEach(pt => b.extend(pt))
  map.fitBounds(b, { padding: 60, maxZoom: 14, duration: 0 })
}

function refresh() {
  if (!map) return
  const src = map.getSource('listings') as import('maplibre-gl').GeoJSONSource | undefined
  popup?.remove()
  clearMarkers()
  src?.setData(geojson())
  fit()
  moved.value = false
}

function searchHere() {
  if (!map) return
  const b = map.getBounds()
  moved.value = false
  emit('searchArea', { south: b.getSouth(), west: b.getWest(), north: b.getNorth(), east: b.getEast() })
}

function setBase(b: MapBase) {
  if (!map || base.value === b) return
  base.value = b
  clearMarkers()
  map.setStyle(styleUrl(b), { diff: false })
}

async function init() {
  if (!import.meta.client || !mapEl.value) return
  ml = await load()
  // Read the theme before the map is made, so a dark page starts with the dark map.
  stopTheme = watchTheme(() => { if (base.value === 'streets' && map) { clearMarkers(); map.setStyle(styleUrl('streets'), { diff: false }) } })
  map = new ml.Map({
    container: mapEl.value,
    style: styleUrl(base.value),
    center: CEBU_CENTER,
    zoom: 11,
    attributionControl: { compact: true },
    dragRotate: false,
    pitchWithRotate: false,
  })
  map.touchZoomRotate.disableRotation()
  map.addControl(new ml.NavigationControl({ showCompass: false }), 'top-right')
  foldAttribution(map)
  map.on('style.load', () => { addSource(); syncMarkers() })
  map.on('render', syncMarkers)
  // Only the buyer's own panning / zooming offers "Search this area".
  map.on('dragend', () => { moved.value = true })
  map.on('zoomend', (e) => { if ((e as { originalEvent?: Event }).originalEvent) moved.value = true })
  map.once('load', fit)
}

onMounted(init)
watch(withCoords, refresh)
watch(() => props.highlightId, applyHighlight)
onUnmounted(() => {
  stopTheme?.()
  popup?.remove()
  clearMarkers()
  map?.remove()
  map = null
})
</script>

<template>
  <div class="relative w-full h-full rounded-2xl overflow-hidden border border-gray-200 dark:border-white/10">
    <div ref="mapEl" class="w-full h-full z-0" @click="onMapClick" />

    <!-- Map / Satellite (MapTiler key only) -->
    <div v-if="hasSatellite" class="absolute left-3 top-3 z-10 inline-flex rounded-full bg-white/95 p-1 shadow-md ring-1 ring-black/5 dark:bg-[#0B1A35]/95 dark:ring-white/10" role="radiogroup" aria-label="Map type">
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

    <!-- Search this area -->
    <Transition name="area">
      <button
        v-if="moved"
        type="button"
        class="absolute left-1/2 top-3 z-10 -translate-x-1/2 inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-brand-navy px-4 py-2 text-xs font-bold text-white shadow-[0_10px_24px_-8px_rgba(6,24,58,0.6)] ring-1 ring-brand-gold/40 hover:bg-[#17417F]"
        :class="hasSatellite ? 'mt-11 sm:mt-0' : ''"
        @click="searchHere"
      >
        <svg class="h-3.5 w-3.5 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        Search this area
      </button>
    </Transition>

    <!-- Empty hint when no plottable properties -->
    <div
      v-if="!withCoords.length"
      class="absolute inset-0 flex flex-col items-center justify-center bg-gray-50/80 dark:bg-[#0B1A35]/70 backdrop-blur-sm pointer-events-none text-center px-6"
    >
      <svg class="h-8 w-8 text-gray-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
      <p class="text-sm text-gray-400">{{ areaActive ? 'No listings in this area. Zoom out or move the map.' : 'No map locations for these listings.' }}</p>
    </div>
  </div>
</template>

<style>
.rl-pin { cursor: pointer; }
.rl-pin.is-hot { z-index: 5; }
.rl-pin.is-hot .map-photo-pin, .rl-pin.is-hot .map-price-pin { transform: scale(1.15); }
.rl-pin.is-hot .map-price-pin, .rl-pin.is-hot .map-photo-pin__price { background: #D4A54A; color: #0B2A5C; }
.map-price-pin {
  background: #0B2A5C; color: #D4A54A; font-weight: 800; font-size: 11px; padding: 4px 10px;
  border-radius: 9999px; border: 2px solid #D4A54A; white-space: nowrap; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  transition: transform 0.15s ease, background 0.15s ease;
}
.map-photo-pin { position: relative; width: 56px; transition: transform 0.15s ease; }
.map-photo-pin:hover { transform: scale(1.08); }
.map-photo-pin__img {
  width: 56px; height: 56px; border-radius: 12px; border: 2px solid #D4A54A; background-color: #0B2A5C;
  background-size: cover; background-position: center; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4);
}
.map-photo-pin__price {
  position: absolute; bottom: -9px; left: 50%; transform: translateX(-50%); background: #0B2A5C; color: #D4A54A;
  font-weight: 800; font-size: 10px; line-height: 1; padding: 3px 8px; border-radius: 9999px; border: 1.5px solid #D4A54A;
  white-space: nowrap; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35);
}
.map-cluster {
  display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 9999px;
  background: #0B2A5C; color: #fff; font-weight: 800; font-size: 13px; border: 3px solid #D4A54A;
  box-shadow: 0 0 0 6px rgba(212, 165, 74, 0.25), 0 6px 16px rgba(0, 0, 0, 0.35); cursor: pointer; transition: transform 0.15s ease;
}
.map-cluster.is-big { width: 48px; height: 48px; font-size: 14px; }
.map-cluster:hover { transform: scale(1.08); }

/* Pin card (see cardHtml). Popups stay light in both themes. */
.rl-pin-popup .maplibregl-popup-content { padding: 0; overflow: hidden; border-radius: 16px; box-shadow: 0 18px 40px -12px rgba(6, 24, 58, 0.45); width: 250px; font-family: inherit; }
.rl-pin-popup .maplibregl-popup-close-button { z-index: 2; top: 6px; right: 6px; width: 26px; height: 26px; border-radius: 9999px; background: rgba(0, 0, 0, 0.45); color: #fff; font-size: 18px; line-height: 24px; }
.pl { padding: 12px 12px 10px; }
.pl-row { display: flex; width: 100%; align-items: baseline; gap: 8px; padding: 7px 6px; border-radius: 10px; text-align: left; background: none; border: 0; cursor: pointer; font: inherit; }
.pl-row:hover { background: rgba(11, 42, 92, 0.06); }
.pl-title { flex: 1; min-width: 0; color: #0B2A5C; font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pl-price { flex: none; color: #85601A; font-size: 12px; font-weight: 800; }
.pc-photo { position: relative; height: 120px; background-size: cover; background-position: center; background-color: #0B2A5C; }
.pc-badge { position: absolute; left: 10px; bottom: 10px; background: rgba(11, 42, 92, 0.9); color: #fff; font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 9999px; }
.pc-body { padding: 12px 14px 14px; }
.pc-price { margin: 0; color: #85601A; font-weight: 800; font-size: 17px; line-height: 1.1; }
.pc-price small { font-size: 11px; font-weight: 600; color: #6B7280; }
.pc-title { margin: 4px 0 0 !important; color: #0B2A5C; font-weight: 700; font-size: 13px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.pc-facts { margin: 4px 0 0 !important; color: #0B2A5C; font-size: 11.5px; font-weight: 600; opacity: 0.75; }
.pc-addr { margin: 2px 0 0 !important; color: #6B7280; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pc-nearby { margin-top: 10px; padding-top: 9px; border-top: 1px solid rgba(11, 42, 92, 0.08); }
.pc-eyebrow { margin: 0 0 5px !important; font-size: 9.5px; font-weight: 800; letter-spacing: 0.14em; text-transform: uppercase; color: rgba(11, 42, 92, 0.45); }
.pc-row { display: flex; align-items: baseline; gap: 6px; font-size: 11.5px; padding: 2px 0; }
.pc-row-label { flex: none; width: 62px; color: rgba(11, 42, 92, 0.55); font-weight: 600; }
.pc-row-name { flex: 1; min-width: 0; color: #0B2A5C; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pc-row-min { flex: none; color: #0B2A5C; font-weight: 800; }
.pc-skel { height: 12px; margin: 5px 0; border-radius: 6px; background: linear-gradient(90deg, #eef1f6, #f7f8fb, #eef1f6); background-size: 200% 100%; animation: pc-shimmer 1.2s linear infinite; }
@keyframes pc-shimmer { to { background-position: -200% 0; } }
.pc-cta { display: block; margin-top: 11px; text-align: center; background: #0B2A5C; color: #fff !important; font-weight: 700; font-size: 12.5px; padding: 8px 0; border-radius: 9999px; text-decoration: none; }
.pc-cta:hover { background: #17417F; }
.area-enter-active, .area-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.area-enter-from, .area-leave-to { opacity: 0; transform: translate(-50%, -6px); }
@media (prefers-reduced-motion: reduce) { .pc-skel { animation: none; } }
</style>
