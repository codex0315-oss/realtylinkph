<script setup lang="ts">
/**
 * "Pin the exact spot" in the listing form. The pin follows the address as it
 * is typed (looked up on the map); the agent can then drag it, or tap the
 * map, to the exact location. Once moved by hand (`pinned`), the address no
 * longer moves it and the coordinates are saved as given instead of being
 * looked up again on the server.
 */
const props = defineProps<{ address: string }>()
const lat = defineModel<number | null>('lat', { default: null })
const lng = defineModel<number | null>('lng', { default: null })
const pinned = defineModel<boolean>('pinned', { default: false })

const { geocode } = useGeocode()
const { styleUrl, load, watchTheme, foldAttribution } = useMapLibre()
const mapEl = ref<HTMLElement | null>(null)
const finding = ref(false)
const notFound = ref(false)
let map: import('maplibre-gl').Map | null = null
let marker: import('maplibre-gl').Marker | null = null
let stopTheme: (() => void) | null = null

function place(lngLat: [number, number], fly = true) {
  marker?.setLngLat(lngLat)
  if (fly) map?.easeTo({ center: lngLat, zoom: Math.max(map.getZoom(), 16) })
}
function setByHand(lngLat: [number, number]) {
  lng.value = Number(lngLat[0].toFixed(7))
  lat.value = Number(lngLat[1].toFixed(7))
  pinned.value = true
  notFound.value = false
}

async function followAddress() {
  if (pinned.value || props.address.trim().length < 6) return
  finding.value = true
  const c = await geocode(props.address)
  finding.value = false
  notFound.value = !c
  if (!c || pinned.value) return
  lat.value = c.lat
  lng.value = c.lng
  place([c.lng, c.lat])
}

async function resetToAddress() {
  pinned.value = false
  await followAddress()
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(() => props.address, () => {
  clearTimeout(timer)
  timer = setTimeout(followAddress, 900)
})

onMounted(async () => {
  if (!mapEl.value) return
  const ml = await load()
  const start: [number, number] = lat.value && lng.value ? [Number(lng.value), Number(lat.value)] : CEBU_CENTER
  // Read the theme before the map is made, so a dark page starts with the dark map.
  stopTheme = watchTheme(() => map?.setStyle(styleUrl('streets'), { diff: false }))
  map = new ml.Map({
    container: mapEl.value,
    style: styleUrl('streets'),
    center: start,
    zoom: lat.value && lng.value ? 16 : 11,
    attributionControl: { compact: true },
    cooperativeGestures: true,
    dragRotate: false,
  })
  map.addControl(new ml.NavigationControl({ showCompass: false }), 'top-right')
  foldAttribution(map)
  const el = document.createElement('div')
  el.className = 'picker-pin'
  el.innerHTML = `<svg width="40" height="40" viewBox="0 0 24 24" fill="#D4A54A" stroke="#0B2A5C" stroke-width="1.4" aria-hidden="true"><path d="M12 21s-7-7.5-7-12a7 7 0 1114 0c0 4.5-7 12-7 12z"/><circle cx="12" cy="9" r="2.6" fill="#0B2A5C"/></svg>`
  marker = new ml.Marker({ element: el, anchor: 'bottom', draggable: true }).setLngLat(start).addTo(map)
  marker.on('dragend', () => { const p = marker!.getLngLat(); setByHand([p.lng, p.lat]) })
  map.on('click', (e) => { place([e.lngLat.lng, e.lngLat.lat], false); setByHand([e.lngLat.lng, e.lngLat.lat]) })
  if (!(lat.value && lng.value)) followAddress()
})
onBeforeUnmount(() => { clearTimeout(timer); stopTheme?.(); map?.remove(); map = null })
</script>

<template>
  <div>
    <div class="relative h-60 overflow-hidden rounded-2xl ring-1 ring-brand-navy/10 dark:ring-white/10">
      <div ref="mapEl" class="h-full w-full" />
      <span v-if="finding" class="absolute left-3 top-3 z-10 rounded-full bg-white/95 px-3 py-1 text-xs font-semibold text-brand-navy shadow dark:bg-[#0B1A35]/95 dark:text-white">Finding the address…</span>
    </div>
    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
      <p class="min-w-0 flex-1" :class="notFound && !pinned ? 'text-amber-700 dark:text-amber-400' : 'text-brand-navy/55 dark:text-white/50'">
        <template v-if="pinned">📍 Pinned by you. Buyers see the home exactly here.</template>
        <template v-else-if="notFound">We couldn’t find that address on the map. Drag the pin or tap the map to set the spot.</template>
        <template v-else>The pin follows the address. Drag it, or tap the map, to set the exact spot.</template>
      </p>
      <button v-if="pinned && address.trim()" type="button" class="flex-none font-semibold text-brand-gold-deep hover:underline dark:text-brand-gold" @click="resetToAddress">Use the address instead</button>
    </div>
  </div>
</template>

<style>
.picker-pin { cursor: grab; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.35)); }
.picker-pin:active { cursor: grabbing; }
</style>
