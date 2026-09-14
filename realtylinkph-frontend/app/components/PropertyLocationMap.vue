<script setup lang="ts">
import 'leaflet/dist/leaflet.css'

const props = defineProps<{
  lat?: number | string | null
  lng?: number | string | null
  address?: string
}>()

const { geocode } = useGeocode()
const mapEl  = ref<HTMLElement | null>(null)
const failed = ref(false)
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let map: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let L: any = null

async function resolveCoords(): Promise<{ lat: number; lng: number } | null> {
  if (props.lat && props.lng) return { lat: Number(props.lat), lng: Number(props.lng) }
  if (props.address) return await geocode(props.address)
  return null
}

async function init() {
  if (!import.meta.client || !mapEl.value) return
  const coords = await resolveCoords()
  if (!coords) { failed.value = true; return }

  L = (await import('leaflet')).default
  map = L.map(mapEl.value, { zoomControl: true, scrollWheelZoom: false })
    .setView([coords.lat, coords.lng], 15)

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19,
  }).addTo(map)

  const icon = L.divIcon({
    className: '',
    html: `<div class="prop-loc-pin">
      <svg width="38" height="38" viewBox="0 0 24 24" fill="#D4AF37" stroke="#08152F" stroke-width="1.4">
        <path d="M12 21s-7-7.5-7-12a7 7 0 1114 0c0 4.5-7 12-7 12z"/>
        <circle cx="12" cy="9" r="2.6" fill="#08152F"/>
      </svg>
    </div>`,
    iconSize: [38, 38],
    iconAnchor: [19, 38],
  })
  L.marker([coords.lat, coords.lng], { icon }).addTo(map)
}

onMounted(init)
onBeforeUnmount(() => { map?.remove?.(); map = null })
</script>

<template>
  <div class="relative w-full h-72 sm:h-80 rounded-2xl overflow-hidden border border-gray-200">
    <div ref="mapEl" class="w-full h-full z-0" />
    <div v-if="failed" class="absolute inset-0 flex flex-col items-center justify-center bg-gray-50 text-center px-6 pointer-events-none">
      <svg class="h-8 w-8 text-gray-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
      <p class="text-sm text-gray-400">Map location unavailable.</p>
      <p v-if="address" class="text-xs text-gray-400 mt-1">{{ address }}</p>
    </div>
  </div>
</template>

<style>
.prop-loc-pin { filter: drop-shadow(0 4px 6px rgba(0,0,0,0.35)); transform: translateY(-2px); }
</style>
