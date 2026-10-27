<script setup lang="ts">
import type { Property } from '~/types'
import type { Nearby } from '~/composables/useNearby'
import { formatDistance } from '~/composables/useNearby'
import 'leaflet/dist/leaflet.css'

interface Props {
  properties: Property[]
}
const props = defineProps<Props>()

const propertyHref = usePropertyHref()
const { fetchNearby } = useNearby()
const mapEl = ref<HTMLElement | null>(null)

/* ── Pin card ──────────────────────────────────────────────────────────
 * Photo, price, key facts and address, then the three nearest essentials
 * (loaded the first time the card opens). Built as an HTML string for
 * Leaflet, so every listing value is escaped first. */
function esc(s: unknown): string {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c]!))
}
function calm(t: string): string {
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
/* Which essentials the card highlights, in order of preference per row. */
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

/* Popup links are plain <a> tags; route them through the app instead of a full reload. */
function onMapClick(e: MouseEvent) {
  const a = (e.target as HTMLElement).closest('a[data-nav]') as HTMLAnchorElement | null
  if (!a) return
  e.preventDefault()
  navigateTo(a.getAttribute('href')!)
}
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let map: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let L: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let markers: any[] = []

const withCoords = computed(() => props.properties.filter(p => p.lat && p.lng))

function priceLabel(p: Property): string {
  const n = Number(p.price)
  if (n >= 1_000_000) return `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
  if (n >= 1_000)     return `₱${(n / 1_000).toFixed(0)}K`
  return `₱${n.toLocaleString('en-PH')}`
}

async function init() {
  if (!import.meta.client || !mapEl.value) return
  L = (await import('leaflet')).default

  // Default view: Philippines
  map = L.map(mapEl.value, { zoomControl: true, scrollWheelZoom: true })
    .setView([12.8797, 121.7740], 5)

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19,
  }).addTo(map)

  renderMarkers()
}

function renderMarkers() {
  if (!map || !L) return

  markers.forEach(m => map.removeLayer(m))
  markers = []
  const pts: [number, number][] = []

  for (const p of withCoords.value) {
    const lat = Number(p.lat)
    const lng = Number(p.lng)

    const photo = p.photos?.[0]?.thumb_url ?? p.photos?.[0]?.url
    const html = photo
      ? `<div class="map-photo-pin">
           <div class="map-photo-pin__img" style="background-image:url('${photo}')"></div>
           <div class="map-photo-pin__price">${priceLabel(p)}</div>
         </div>`
      : `<div class="map-price-pin">${priceLabel(p)}</div>`

    const icon = L.divIcon({
      className: '',
      html,
      iconSize: [0, 0],
    })

    const marker = L.marker([lat, lng], { icon }).addTo(map)
    marker.bindPopup(cardHtml(p, 'loading'), { minWidth: 240, maxWidth: 260, className: 'rl-pin-popup' })
    marker.on('popupopen', async () => {
      const n = await fetchNearby(p.id)
      marker.setPopupContent(cardHtml(p, n))
    })
    markers.push(marker)
    pts.push([lat, lng])
  }

  if (pts.length) {
    map.fitBounds(pts, { padding: [50, 50], maxZoom: 14 })
  }
}

onMounted(init)
watch(withCoords, renderMarkers)
onUnmounted(() => {
  if (map) {
    map.remove()
    map = null
  }
})
</script>

<template>
  <div class="relative w-full h-full rounded-2xl overflow-hidden border border-gray-200">
    <div ref="mapEl" class="w-full h-full z-0" @click="onMapClick" />

    <!-- Empty hint when no plottable properties -->
    <div
      v-if="!withCoords.length"
      class="absolute inset-0 flex flex-col items-center justify-center bg-gray-50/80 backdrop-blur-sm pointer-events-none text-center px-6"
    >
      <svg class="h-8 w-8 text-gray-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
      <p class="text-sm text-gray-400">No map locations for these listings.</p>
    </div>
  </div>
</template>

<style>
.map-price-pin {
  background: #0B2A5C;
  color: #D4A54A;
  font-weight: 800;
  font-size: 11px;
  padding: 4px 10px;
  border-radius: 9999px;
  border: 2px solid #D4A54A;
  white-space: nowrap;
  transform: translate(-50%, -50%);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  cursor: pointer;
}

/* Photo-thumbnail pin: property image with a price tag below. */
.map-photo-pin {
  position: relative;
  width: 56px;
  transform: translate(-50%, -50%);
  cursor: pointer;
  transition: transform 0.15s ease;
}
.map-photo-pin:hover {
  transform: translate(-50%, -50%) scale(1.08);
  z-index: 1000;
}
.map-photo-pin__img {
  width: 56px;
  height: 56px;
  border-radius: 12px;
  border: 2px solid #D4A54A;
  background-color: #0B2A5C;
  background-size: cover;
  background-position: center;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4);
}
.map-photo-pin__price {
  position: absolute;
  bottom: -9px;
  left: 50%;
  transform: translateX(-50%);
  background: #0B2A5C;
  color: #D4A54A;
  font-weight: 800;
  font-size: 10px;
  line-height: 1;
  padding: 3px 8px;
  border-radius: 9999px;
  border: 1.5px solid #D4A54A;
  white-space: nowrap;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35);
}
.leaflet-popup-content-wrapper {
  border-radius: 12px;
}

/* Pin card (see cardHtml). Leaflet popups stay light in both themes. */
.rl-pin-popup .leaflet-popup-content-wrapper { padding: 0; overflow: hidden; border-radius: 16px; box-shadow: 0 18px 40px -12px rgba(6, 24, 58, 0.45); }
.rl-pin-popup .leaflet-popup-content { margin: 0; width: 250px !important; font-family: inherit; }
.rl-pin-popup .leaflet-popup-close-button { z-index: 2; top: 6px !important; right: 6px !important; width: 26px !important; height: 26px !important; border-radius: 9999px; background: rgba(0, 0, 0, 0.45); color: #fff !important; font-size: 18px !important; line-height: 24px !important; text-align: center; }
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
@media (prefers-reduced-motion: reduce) { .pc-skel { animation: none; } }
</style>
