<script setup lang="ts">
import type { Property } from '~/types'
import 'leaflet/dist/leaflet.css'

interface Props {
  properties: Property[]
}
const props = defineProps<Props>()

const propertyHref = usePropertyHref()
const mapEl = ref<HTMLElement | null>(null)
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
    marker.bindPopup(
      `<div style="min-width:170px">
        <strong style="color:#08152F">${p.title}</strong><br/>
        <span style="color:#D4AF37;font-weight:700">${priceLabel(p)}</span><br/>
        <span style="color:#6B7280;font-size:11px">${p.address ?? ''}</span><br/>
        <a href="${propertyHref(p.id)}" style="color:#08152F;font-size:12px;font-weight:600">View details →</a>
      </div>`,
    )
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
    <div ref="mapEl" class="w-full h-full z-0" />

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
  background: #08152F;
  color: #D4AF37;
  font-weight: 800;
  font-size: 11px;
  padding: 4px 10px;
  border-radius: 9999px;
  border: 2px solid #D4AF37;
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
  border: 2px solid #D4AF37;
  background-color: #08152F;
  background-size: cover;
  background-position: center;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4);
}
.map-photo-pin__price {
  position: absolute;
  bottom: -9px;
  left: 50%;
  transform: translateX(-50%);
  background: #08152F;
  color: #D4AF37;
  font-weight: 800;
  font-size: 10px;
  line-height: 1;
  padding: 3px 8px;
  border-radius: 9999px;
  border: 1.5px solid #D4AF37;
  white-space: nowrap;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35);
}
.leaflet-popup-content-wrapper {
  border-radius: 12px;
}
</style>
